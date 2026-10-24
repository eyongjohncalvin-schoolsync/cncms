<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Budget;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\Scopes\BranchScope;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-category monthly budgets (E5 of
 * docs/plans/expenditures-and-spending-controls.md): company-wide (E10),
 * warnings only — amber at 80 %, red at 100 %, never a block. Actuals are
 * matched by category id. A closed month's budgets are read-only.
 */
class BudgetService
{
    public function __construct(
        private readonly ExpenditureMonthService $months,
        private readonly SpendingControlService $spending,
    ) {}

    /**
     * Categories × {last month, this month} grid for Resources → Budgets.
     *
     * @return array{month: string, previous: string, closed: bool, cap: ?string, budget_total: string, previous_budget_total: string,
     *     rows: list<array{uuid: string, name: string, is_active: bool, previous_budget: ?string, previous_actual: string, budget: ?string, actual: string, pct: ?float, level: string}>}
     */
    public function grid(string $period): array
    {
        $previous = BusinessTimezone::previousPeriod($period);

        // Branches v2: a branch view works on that branch's own budgets and spending; all branches = the company's.
        $branchId = TenantContext::currentBranchId();
        $budgets = $this->budgetsFor($branchId)->whereIn('period', [$period, $previous])->get()->groupBy('period');
        $current = ($budgets[$period] ?? collect())->pluck('amount', 'category_id');
        $prior = ($budgets[$previous] ?? collect())->pluck('amount', 'category_id');
        $actuals = $this->actuals($period, $branchId);
        $priorActuals = $this->actuals($previous, $branchId);

        $categories = ExpenseCategory::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $current->keys()->merge($actuals->keys())))
            ->orderBy('sort_order')->orderBy('name')->get();

        $rows = $categories->map(function (ExpenseCategory $category) use ($current, $prior, $actuals, $priorActuals): array {
            $budget = isset($current[$category->id]) ? (string) $current[$category->id] : null;
            $actual = $this->money($actuals[$category->id] ?? 0);
            $pct = $budget !== null && (float) $budget > 0 ? round(((float) $actual / (float) $budget) * 100, 1) : null;

            return [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'is_active' => (bool) $category->is_active,
                'previous_budget' => isset($prior[$category->id]) ? (string) $prior[$category->id] : null,
                'previous_actual' => $this->money($priorActuals[$category->id] ?? 0),
                'budget' => $budget,
                'actual' => $actual,
                'pct' => $pct,
                'level' => $this->spending->level($pct),
            ];
        })->values()->all();

        // The monthly cap is a company ceiling (D10), shown on the company view only.
        $cap = $branchId === null ? Company::cached()?->monthly_spending_cap : null;

        return [
            'branch' => $branchId !== null ? Branch::allMemoized()->get($branchId)?->name : null,
            'month' => $period,
            'previous' => $previous,
            'closed' => $this->months->isClosed($period),
            'cap' => $cap !== null ? (string) $cap : null,
            'budget_total' => $this->money($current->sum()),
            'previous_budget_total' => $this->money($prior->sum()),
            'rows' => $rows,
        ];
    }

    /**
     * Saves one month's budgets. Blank / zero removes a category's budget.
     *
     * @param  array<string, mixed>  $amountsByCategoryUuid
     */
    public function save(string $period, array $amountsByCategoryUuid): void
    {
        $this->months->assertOpen($period, 'month');

        $ids = ExpenseCategory::query()->whereIn('uuid', array_keys($amountsByCategoryUuid))->pluck('id', 'uuid');

        $branchId = TenantContext::currentBranchId();

        DB::transaction(function () use ($period, $amountsByCategoryUuid, $ids, $branchId): void {
            foreach ($amountsByCategoryUuid as $uuid => $amount) {
                $categoryId = $ids[$uuid] ?? null;

                if ($categoryId === null) {
                    continue;
                }

                if ($amount === null || $amount === '' || (float) $amount <= 0) {
                    $this->budgetsFor($branchId)->where('period', $period)->where('category_id', $categoryId)->get()->each->delete();

                    continue;
                }

                Budget::query()->updateOrCreate(
                    ['period' => $period, 'category_id' => $categoryId, 'branch_id' => $branchId],
                    ['amount' => number_format((float) $amount, 2, '.', '')],
                );
            }
        });
    }

    /**
     * Copies last month's budgets into $period for every category that has
     * none yet. Returns how many were copied.
     */
    public function copyFromPrevious(string $period): int
    {
        $this->months->assertOpen($period, 'month');

        $branchId = TenantContext::currentBranchId();
        $existing = $this->budgetsFor($branchId)->where('period', $period)->pluck('category_id');
        $copied = 0;

        foreach ($this->budgetsFor($branchId)->where('period', BusinessTimezone::previousPeriod($period))->whereNotIn('category_id', $existing)->get() as $budget) {
            Budget::query()->create(['period' => $period, 'category_id' => $budget->category_id, 'amount' => $budget->amount, 'branch_id' => $branchId]);
            $copied++;
        }

        return $copied;
    }

    /**
     * One branch's budgets, or the company's (null) — branches v2: a branch
     * manager edits their branch's budgets, never the company's.
     *
     * @return Builder<Budget>
     */
    private function budgetsFor(?int $branchId): Builder
    {
        return $branchId === null ? Budget::query()->whereNull('branch_id') : Budget::query()->where('branch_id', $branchId);
    }

    /**
     * @return Collection<int, mixed> category id => total
     */
    private function actuals(string $period, ?int $branchId): Collection
    {
        [$from, $to] = BusinessTimezone::periodDates($period);

        return Expenditure::query()->withoutGlobalScope(BranchScope::class)->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))->counted()->whereBetween('spent_at', [$from, $to])
            ->groupBy('category_id')->selectRaw('category_id, sum(amount) as total')->pluck('total', 'category_id');
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?: '0'), '0', 2);
    }
}
