<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Budget;
use App\Models\Expenditure;
use App\Models\Payment;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Builds the Resources / P&L dashboard payload described in api-spec.md
 * section 6.5. Income and expense totals are computed with direct,
 * lightweight queries (mirroring how ManuscriptRepository::collectedForPeriod()
 * derives "collected" independently of the manuscripts table) rather than
 * through a dedicated repository — this is a read-only aggregation service,
 * not a CRUD concern for any single model.
 */
class ResourcesDashboardService
{

    /**
     * @return array{
     *     period: string,
     *     income: array{total: string, verified: string, pending_verification: string, rejected: string, payment_count: int},
     *     expenses: array{total: string, by_category: list<array{name: string, amount: string, count: int}>},
     *     pnl: array{net: string, margin_pct: float},
     *     budgets: list<array{category: string, budgeted: string, actual: string, variance: string, variance_pct: float}>,
     * }
     */
    public function dashboard(?string $period): array
    {
        $period = $this->resolvePeriod($period);
        $branchId = TenantContext::currentBranchId();

        // Expenditures get recorded throughout the day by agents/managers, so
        // this needs to be fresher than the once-a-month manuscript data —
        // 5 minutes is a reasonable balance. ExpenditureService::create()/
        // update()/delete() forget this exact key for the affected
        // expenditure's spent_at period so newly-recorded expenses show up
        // promptly instead of waiting out the full TTL.
        //
        // Branch fence baked into the key — see the identical note on
        // App\Services\CustomerService::list(): incomeFor() below is now
        // branch-scoped, so the cache must not be shared across callers with
        // different effective branches.
        return Cache::remember(
            'resources:dashboard:'.$period.':'.($branchId ?? 'all'),
            now()->addMinutes(5),
            function () use ($period, $branchId): array {
                [$start, $end] = $this->periodBounds($period);

                $income = $this->incomeFor($start, $end, $branchId);
                $expenses = $this->expensesFor($period);
                $pnl = $this->pnlFor($income['verified'], $expenses['total']);
                $budgets = $this->budgetsFor($period, $expenses['by_category']);

                return [
                    'period' => $period,
                    'income' => $income,
                    'expenses' => $expenses,
                    'pnl' => $pnl,
                    'budgets' => $budgets,
                ];
            },
        );
    }

    private function resolvePeriod(?string $period): string
    {
        $period ??= Carbon::now()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw ValidationException::withMessages(['period' => ['The period must be in YYYY-MM format.']]);
        }

        return $period;
    }

    /**
     * UTC instant bounds for the period's calendar month, for comparing
     * against `timestamptz` columns (payments.created_at) — see
     * incomeFor(). Not used for `spent_at` (a plain DATE column with no
     * time-of-day/timezone component); expensesFor() derives its own
     * calendar-date bounds directly from the period so it isn't affected by
     * this UTC conversion.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodBounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period, BusinessTimezone::WAT)
            ->startOfMonth()
            ->setTimezone('UTC');
        $end = Carbon::createFromFormat('Y-m', $period, BusinessTimezone::WAT)
            ->endOfMonth()
            ->setTimezone('UTC');

        return [$start, $end];
    }

    /**
     * Income is derived from `payments.created_at` falling inside the
     * period's calendar month — same convention ManuscriptRepository uses
     * for "collected" — rather than `processed_at`, since manuscript
     * processing may lag behind the period the payment was actually made in.
     *
     * @return array{total: string, verified: string, pending_verification: string, rejected: string, payment_count: int}
     */
    private function incomeFor(Carbon $start, Carbon $end, ?int $branchId): array
    {
        // A single query with FILTER clauses (Postgres-specific) instead of
        // 3 separate sum() queries (one per verification_status) plus a
        // count() — all 4 were scanning the same created_at date range.
        $row = Payment::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($branchId !== null, fn ($query) => $query->whereHas(
                'customer.zone',
                fn ($inner) => $inner->where('branch_id', $branchId),
            ))
            ->selectRaw("
                coalesce(sum(amount) filter (where verification_status = 'verified'), 0) as verified,
                coalesce(sum(amount) filter (where verification_status = 'pending'), 0) as pending,
                coalesce(sum(amount) filter (where verification_status = 'rejected'), 0) as rejected,
                count(*) as payment_count
            ")
            ->first();

        $verified = (string) $row->verified;
        $pending = (string) $row->pending;
        $rejected = (string) $row->rejected;

        return [
            'total' => bcadd(bcadd($verified, $pending, 2), $rejected, 2),
            'verified' => $verified,
            'pending_verification' => $pending,
            'rejected' => $rejected,
            'payment_count' => (int) $row->payment_count,
        ];
    }

    /**
     * Expenses are derived from `expenditures.spent_at` (the date the money
     * was actually spent), not `created_at` (when it was recorded) — the
     * field that exists precisely so offline/backdated entries land in the
     * right period.
     *
     * Not branch-scoped: `expenditures` has no zone/branch association in the
     * schema today (it belongs to an ExpenseCategory and a recording User,
     * neither of which reliably maps to a branch — a user can move branches,
     * and an expense category is tenant-wide). Adding branch attribution to
     * expenses is a schema change, not a query fix, so it's left as a known
     * gap rather than bolted on here.
     *
     * @return array{total: string, by_category: list<array{name: string, amount: string, count: int}>}
     */
    private function expensesFor(string $period): array
    {
        // spent_at is a plain DATE column (no time-of-day/timezone
        // component), so its calendar-month bounds are just the period's
        // first/last day as written — no UTC conversion belongs here (see
        // periodBounds()'s docblock).
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m', $period)->endOfMonth()->toDateString();

        $rows = Expenditure::query()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenditures.category_id')
            ->whereBetween('expenditures.spent_at', [$start, $end])
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.sort_order')
            ->orderBy('expense_categories.sort_order')
            ->selectRaw('expense_categories.name as name, coalesce(sum(expenditures.amount), 0) as amount, count(expenditures.id) as count')
            ->get();

        $byCategory = $rows->map(fn ($row): array => [
            'name' => (string) $row->name,
            'amount' => (string) $row->amount,
            'count' => (int) $row->count,
        ])->values()->all();

        $total = array_reduce(
            $byCategory,
            static fn (string $carry, array $row): string => bcadd($carry, $row['amount'], 2),
            '0.00'
        );

        return [
            'total' => $total,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Net/margin are computed against verified income only, per
     * SKILL.md's Resources module spec ("Income: sum of payments.amount
     * for the period (only verified payments)") — unverified or rejected
     * payments should not inflate the reported margin.
     *
     * @return array{net: string, margin_pct: float}
     */
    private function pnlFor(string $verifiedIncome, string $expensesTotal): array
    {
        $net = bcsub($verifiedIncome, $expensesTotal, 2);

        $marginPct = bccomp($verifiedIncome, '0.00', 2) > 0
            ? round(((float) $net / (float) $verifiedIncome) * 100, 1)
            : 0.0;

        return [
            'net' => $net,
            'margin_pct' => $marginPct,
        ];
    }

    /**
     * Only present when at least one budget row exists for the period —
     * absent entirely otherwise, per api-spec.md section 6.5's example
     * (an operator that hasn't set budgets simply sees no variance section).
     *
     * @param  list<array{name: string, amount: string, count: int}>  $actualsByCategory
     * @return list<array{category: string, budgeted: string, actual: string, variance: string, variance_pct: float}>
     */
    private function budgetsFor(string $period, array $actualsByCategory): array
    {
        $budgets = Budget::query()->with('category')->where('period', $period)->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        $actualsByName = collect($actualsByCategory)->keyBy('name');

        return $budgets->map(function (Budget $budget) use ($actualsByName): array {
            $categoryName = $budget->category->name;
            $budgeted = (string) $budget->amount;
            $actual = (string) ($actualsByName->get($categoryName)['amount'] ?? '0.00');
            $variance = bcsub($budgeted, $actual, 2);

            $variancePct = bccomp($budgeted, '0.00', 2) > 0
                ? round(((float) $variance / (float) $budgeted) * 100, 1)
                : 0.0;

            return [
                'category' => $categoryName,
                'budgeted' => $budgeted,
                'actual' => $actual,
                'variance' => $variance,
                'variance_pct' => $variancePct,
            ];
        })->values()->all();
    }
}
