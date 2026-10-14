<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateExpenseLimitsRequest;
use App\Models\Budget;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\ExpenseControlService;
use App\Services\RecurringExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resources → Budgets & limits: the monthly budget per category (with what
 * has been spent against it), the regular monthly expenses (rent,
 * salaries, electricity…) and the approval / evidence limits.
 */
class ResourceSettingsController extends Controller
{
    public function __construct(
        private readonly ExpenseControlService $controls,
        private readonly RecurringExpenseService $recurring,
    ) {}

    public function edit(Request $request): Response
    {
        $this->authorize('viewAny', Expenditure::class);

        $period = $this->period($request->query('period'));
        $start = Carbon::createFromFormat('!Y-m', $period)->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('!Y-m', $period)->endOfMonth()->toDateString();

        $categories = ExpenseCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'uuid', 'name']);
        $budgets = Budget::query()->where('period', $period)->pluck('amount', 'category_id');

        // Approved and still-pending spending per category, in one query.
        $spent = Expenditure::query()
            ->whereBetween('spent_at', [$start, $end])
            ->whereNotIn('status', [Expenditure::STATUS_REJECTED, Expenditure::STATUS_VOID])
            ->groupBy('category_id')
            ->selectRaw("category_id, sum(case when status = 'approved' then amount else 0 end) as approved, sum(case when status <> 'approved' then amount else 0 end) as pending")
            ->get()
            ->keyBy('category_id');

        return Inertia::render('Resources/Settings', [
            'period' => $period,
            'can_manage' => $request->user()->can('manageResourcesSettings', Expenditure::class),
            'can_record' => $request->user()->can('create', Expenditure::class),
            'limits' => $this->controls->limits(),
            'budgets' => $categories->map(fn (ExpenseCategory $category): array => [
                'category_uuid' => $category->uuid,
                'category_name' => $category->name,
                'budget' => isset($budgets[$category->id]) ? (string) $budgets[$category->id] : null,
                'approved' => (string) ($spent->get($category->id)?->approved ?? '0'),
                'pending' => (string) ($spent->get($category->id)?->pending ?? '0'),
            ])->values()->all(),
            'recurring' => $this->recurring->forPeriod($period),
            'categories' => $categories->map(fn (ExpenseCategory $category): array => ['uuid' => $category->uuid, 'name' => $category->name])->values()->all(),
            'suppliers' => Supplier::query()->where('status', Supplier::STATUS_ACTIVE)->orderBy('name')->get(['uuid', 'name'])->toArray(),
            'services' => Service::query()->where('active', true)->orderBy('sort_order')->get(['uuid', 'name'])->toArray(),
        ]);
    }

    public function updateLimits(UpdateExpenseLimitsRequest $request): RedirectResponse
    {
        $company = Company::query()->first();
        abort_if($company === null, 404);

        $company->update($request->validated());
        Company::forgetCache();

        return back()->with('success', 'Approval limits saved.');
    }

    /**
     * Saves the month's budgets in one go; a blank or zero amount removes
     * that category's budget.
     */
    public function saveBudgets(Request $request): RedirectResponse
    {
        $this->authorize('manageResourcesSettings', Expenditure::class);

        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'budgets' => ['present', 'array', 'max:200'],
            'budgets.*.category_uuid' => ['required', 'uuid', 'distinct'],
            'budgets.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $categoryIds = ExpenseCategory::query()
            ->whereIn('uuid', array_column($validated['budgets'], 'category_uuid'))
            ->pluck('id', 'uuid');

        DB::transaction(function () use ($validated, $categoryIds): void {
            foreach ($validated['budgets'] as $row) {
                $categoryId = $categoryIds[$row['category_uuid']] ?? null;

                if ($categoryId === null) {
                    continue;
                }

                if (empty($row['amount']) || (float) $row['amount'] <= 0) {
                    Budget::query()->where('category_id', $categoryId)->where('period', $validated['period'])->delete();

                    continue;
                }

                Budget::query()->updateOrCreate(
                    ['category_id' => $categoryId, 'period' => $validated['period']],
                    ['amount' => $row['amount']],
                );
            }
        });

        return back()->with('success', 'Budgets saved.');
    }

    /**
     * Copies last month's budgets into a month that has none yet.
     */
    public function copyBudgets(Request $request): RedirectResponse
    {
        $this->authorize('manageResourcesSettings', Expenditure::class);

        $period = $this->period($request->input('period'));
        $previous = Carbon::createFromFormat('!Y-m', $period)->subMonthNoOverflow()->format('Y-m');
        $existing = Budget::query()->where('period', $period)->pluck('category_id')->all();

        $copied = 0;

        foreach (Budget::query()->where('period', $previous)->whereNotIn('category_id', $existing)->get() as $budget) {
            Budget::query()->create(['category_id' => $budget->category_id, 'period' => $period, 'amount' => $budget->amount]);
            $copied++;
        }

        return back()->with('success', $copied === 0 ? 'Nothing to copy.' : "Copied {$copied} budgets from last month.");
    }

    public function storeRecurring(Request $request): RedirectResponse
    {
        $this->authorize('manageResourcesSettings', Expenditure::class);

        RecurringExpense::query()->create([
            ...$this->recurringAttributes($request),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Regular expense added.');
    }

    public function updateRecurring(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorize('manageResourcesSettings', Expenditure::class);

        $recurringExpense->update($this->recurringAttributes($request, partial: true));

        return back()->with('success', 'Regular expense updated.');
    }

    /**
     * Records this month's expense from a template (one tap). The usual
     * approval limits apply to it like any other expense.
     */
    public function recordRecurring(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorize('create', Expenditure::class);

        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m', 'before_or_equal:'.Carbon::now()->format('Y-m')],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
        ]);

        $expenditure = $this->recurring->record(
            $recurringExpense,
            $validated['period'],
            $request->user(),
            isset($validated['amount']) ? (string) $validated['amount'] : null,
        );

        return back()->with('success', $expenditure->status === Expenditure::STATUS_APPROVED
            ? "{$recurringExpense->name} recorded."
            : "{$recurringExpense->name} recorded — waiting for approval.");
    }

    /**
     * @return array<string, mixed>
     */
    private function recurringAttributes(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'category_uuid' => [$required, 'uuid', Rule::exists('expense_categories', 'uuid')],
            'supplier_uuid' => ['nullable', 'uuid', Rule::exists('suppliers', 'uuid')],
            'service_uuid' => ['nullable', 'uuid', Rule::exists('services', 'uuid')],
            'amount' => [$required, 'numeric', 'gt:0', 'max:999999999'],
            'payment_method' => ['nullable', Rule::in(Expenditure::PAYMENT_METHODS)],
            'day_of_month' => [$required, 'integer', 'between:1,28'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $attributes = collect($validated)->except(['category_uuid', 'supplier_uuid', 'service_uuid'])->all();

        if (array_key_exists('category_uuid', $validated)) {
            $attributes['category_id'] = ExpenseCategory::query()->where('uuid', $validated['category_uuid'])->value('id');
        }

        if (array_key_exists('supplier_uuid', $validated)) {
            $attributes['supplier_id'] = $validated['supplier_uuid'] ? Supplier::query()->where('uuid', $validated['supplier_uuid'])->value('id') : null;
        }

        if (array_key_exists('service_uuid', $validated)) {
            $attributes['service_id'] = $validated['service_uuid'] ? Service::query()->where('uuid', $validated['service_uuid'])->value('id') : null;
        }

        return $attributes;
    }

    private function period(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : Carbon::now()->format('Y-m');
    }
}
