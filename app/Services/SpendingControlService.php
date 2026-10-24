<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Budget;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Scopes\BranchScope;
use App\Models\Voucher;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The month's spending picture (docs/plans/expenditures-and-spending-controls.md,
 * items 4–6): what was spent, what is committed against the monthly cap,
 * per-category budgets (warn only — amber at 80 %, red at 100 %), and the
 * deficit warning (expenses vs verified income; technician fees are never
 * income). Company-wide (E10). Read-only — VoucherService asks it whether a
 * bon goes over the cap; screens and the alerts digest show its summary.
 */
class SpendingControlService
{
    public const int BUDGET_AMBER_PCT = 80;

    public const int BUDGET_RED_PCT = 100;

    /**
     * Counted (not voided) expenditures whose spent_at falls in $period —
     * company-wide unless a branch is given (branches v2: the cap is a
     * company ceiling, D10).
     */
    public function spentInPeriod(string $period, ?int $branchId = null): string
    {
        [$from, $to] = BusinessTimezone::periodDates($period);

        return $this->money($this->forBranch(Expenditure::query(), $branchId)->counted()->whereBetween('spent_at', [$from, $to])->sum('amount'));
    }

    /**
     * Committed spend for the month = what was spent (paid/settled bons,
     * corrections, legacy rows) + approved bons not yet paid + cash advances
     * paid but not yet settled. Only the current month has outstanding
     * commitments; a past month's committed spend is what it spent.
     */
    public function committedSpend(string $period, ?int $excludeVoucherId = null, ?int $branchId = null): string
    {
        $committed = $this->spentInPeriod($period, $branchId);

        if ($period !== BusinessTimezone::currentPeriod()) {
            return $committed;
        }

        $outstanding = $this->forBranch(Voucher::query(), $branchId)
            ->where(fn ($query) => $query
                ->where('status', 'approved')
                ->orWhere(fn ($inner) => $inner->where('kind', Voucher::KIND_ADVANCE)->where('status', 'paid')))
            ->when($excludeVoucherId !== null, fn ($query) => $query->whereKeyNot($excludeVoucherId))
            ->sum('amount');

        return bcadd($committed, $this->money($outstanding), 2);
    }

    /** Whether committed spend for this month plus $amount goes over the cap. */
    public function exceedsCap(string $amount, ?int $excludeVoucherId = null): bool
    {
        $cap = Company::cached()?->monthly_spending_cap;

        if ($cap === null) {
            return false;
        }

        $committed = $this->committedSpend(BusinessTimezone::currentPeriod(), $excludeVoucherId);

        return bccomp(bcadd($committed, $amount, 2), (string) $cap, 2) > 0;
    }

    /**
     * Verified income for the month — subscription + installation, never
     * technician fees (Payment::income()). Company-wide unless a branch is given.
     */
    public function verifiedIncome(string $period, ?int $branchId = null): string
    {
        [$start, $end] = BusinessTimezone::periodBoundsUtc($period);

        return $this->money(Payment::query()
            ->when($branchId !== null, fn ($query) => $query->where('payments.branch_id', $branchId))
            ->income()
            ->where('verification_status', 'verified')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount'));
    }

    /**
     * @return array{
     *     period: string, spent: string, committed: string, cap: ?string, cap_pct: ?float,
     *     income: string, net: string, deficit: bool, budget_total: string,
     *     categories: list<array{uuid: string, name: string, amount: string, budget: ?string, pct: ?float, level: string}>,
     *     budget_warnings: int,
     * }
     */
    public function summary(string $period): array
    {
        [$from, $to] = BusinessTimezone::periodDates($period);

        // Branches v2: a fenced viewer sees their own branch's spending,
        // income and budgets — never company totals. The monthly cap is a
        // company ceiling (D10), so it is left out of a branch view.
        $branchId = TenantContext::currentBranchId();

        $actuals = $this->forBranch(Expenditure::query(), $branchId)
            ->counted()
            ->whereBetween('spent_at', [$from, $to])
            ->groupBy('category_id')
            ->selectRaw('category_id, sum(amount) as total')
            ->pluck('total', 'category_id');

        // A branch's own budgets in a branch view (branches v2 P5); the company's otherwise.
        $budgets = Budget::query()->where('period', $period)
            ->when($branchId === null, fn ($query) => $query->whereNull('branch_id'), fn ($query) => $query->where('branch_id', $branchId))
            ->pluck('amount', 'category_id');

        $categoryIds = $actuals->keys()->merge($budgets->keys())->unique()->values();
        $categories = ExpenseCategory::query()->whereIn('id', $categoryIds)->orderBy('sort_order')->orderBy('name')->get();

        $rows = $categories->map(function (ExpenseCategory $category) use ($actuals, $budgets): array {
            $amount = $this->money($actuals[$category->id] ?? 0);
            $budget = isset($budgets[$category->id]) ? $this->money($budgets[$category->id]) : null;
            $pct = $this->pct($amount, $budget);

            return [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'amount' => $amount,
                'budget' => $budget,
                'pct' => $pct,
                'level' => $this->level($pct),
            ];
        })->values()->all();

        $spent = $this->spentInPeriod($period, $branchId);
        $committed = $this->committedSpend($period, branchId: $branchId);
        $cap = $branchId === null ? Company::cached()?->monthly_spending_cap : null;
        $income = $this->verifiedIncome($period, $branchId);
        $net = bcsub($income, $spent, 2);

        return [
            'period' => $period,
            'spent' => $spent,
            'committed' => $committed,
            'cap' => $cap !== null ? $this->money($cap) : null,
            'cap_pct' => $cap !== null ? $this->pct($committed, $this->money($cap)) : null,
            'income' => $income,
            'net' => $net,
            'deficit' => bccomp($net, '0', 2) < 0,
            'budget_total' => $this->money($budgets->sum()),
            'categories' => $rows,
            'budget_warnings' => count(array_filter($rows, fn (array $row): bool => $row['level'] !== 'ok')),
        ];
    }

    /** `ok`, `amber` (≥ 80 % of the budget) or `red` (≥ 100 %). */
    public function level(?float $pct): string
    {
        return match (true) {
            $pct === null => 'ok',
            $pct >= self::BUDGET_RED_PCT => 'red',
            $pct >= self::BUDGET_AMBER_PCT => 'amber',
            default => 'ok',
        };
    }

    private function pct(string $amount, ?string $of): ?float
    {
        if ($of === null || bccomp($of, '0', 2) <= 0) {
            return null;
        }

        return round(((float) $amount / (float) $of) * 100, 1);
    }

    /**
     * Money-out rows of one branch, or of the whole company (null) — never
     * narrowed by the caller's own fence, so limits stay company-wide.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function forBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->withoutGlobalScope(BranchScope::class)
            ->when($branchId !== null, fn (Builder $inner) => $inner->where($inner->getModel()->qualifyColumn('branch_id'), $branchId));
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?: '0'), '0', 2);
    }
}
