<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ExpendituresExport;
use App\Http\Requests\ReopenExpenditureMonthRequest;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Http\Requests\UpdateExpenditureRequest;
use App\Http\Requests\UpdateExpenseCategoryRequest;
use App\Http\Requests\VoidExpenditureRequest;
use App\Models\AuditLog;
use App\Models\Expenditure;
use App\Models\ExpenditureMonth;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\ExpenditureMonthService;
use App\Services\ExpenditureService;
use App\Services\ExpenseCategoryService;
use App\Services\ResourcesDashboardService;
use App\Services\SpendingControlService;
use App\Support\BusinessTimezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Resources: the P&L dashboard, expenditures and categories (web, Inertia).
 *
 * Expenditures follow docs/plans/expenditures-and-spending-controls.md:
 * nothing is recorded here any more (E1 — every cash-out is a bon); the
 * index is one month at a time (the current Cameroon month by default,
 * past months as a read-only archive once closed) with a spending header
 * (spent, cap, budget, income, net); the detail page shows the history and
 * lets admin/owner re-categorise or void (legacy rows) with a reason in an
 * open month; the owner closes and reopens months.
 */
class ResourceController extends Controller
{
    public function __construct(
        private readonly ExpenditureService $expenditures,
        private readonly ExpenseCategoryService $categories,
        private readonly ResourcesDashboardService $dashboard,
        private readonly SpendingControlService $spending,
        private readonly ExpenditureMonthService $months,
    ) {}

    public function dashboard(Request $request): Response
    {
        $this->authorize('viewDashboard', Expenditure::class);

        $period = $request->query('period');

        $data = $this->dashboard->dashboard($period !== null ? (string) $period : null);

        return Inertia::render('Resources/Dashboard', [
            ...$data,
            // Cap, budget warnings and the deficit indicator (items 5–6).
            'spending' => $this->spending->summary($data['period']),
        ]);
    }

    public function expenditures(Request $request): Response
    {
        $this->authorize('viewAny', Expenditure::class);

        $filters = $this->indexFilters($request);
        $month = $filters['month'];
        $paginator = $this->expenditures->list($filters, (int) $request->integer('per_page', 25));
        $raw = $paginator->toArray();
        $monthRow = $this->months->find($month);

        return Inertia::render('Resources/Expenditures/Index', [
            'month' => $month,
            'current_month' => BusinessTimezone::currentPeriod(),
            'month_status' => $this->shapeMonth($month, $monthRow),
            'months' => $this->archiveMonths(),
            'summary' => $this->spending->summary($month),
            'expenditures' => [
                'data' => $paginator->getCollection()->map(fn (Expenditure $expenditure): array => $this->formatExpenditure($expenditure))->values()->all(),
                'links' => $raw['links'],
                'meta' => [
                    'current_page' => $raw['current_page'],
                    'per_page' => $raw['per_page'],
                    'total' => $raw['total'],
                    'last_page' => $raw['last_page'],
                ],
            ],
            'filters' => [
                'month' => $month,
                'category_uuid' => $filters['category_uuid'] ?? null,
                'source' => $filters['source'] ?? null,
                'status' => $filters['status'] ?? null,
                'user_uuid' => $filters['user_uuid'] ?? null,
            ],
            'categories' => $this->categoryOptions(),
            'recorders' => $this->recordersFor($month),
            'can' => [
                'manage_months' => $request->user()->can('manageMonths', Expenditure::class),
                'manage_budgets' => $request->user()->can('manageBudgets', Expenditure::class),
            ],
        ]);
    }

    public function exportExpenditures(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Expenditure::class);

        $filters = $this->indexFilters($request);

        return Excel::download(
            new ExpendituresExport($filters['month'], $this->expenditures->all($filters)),
            "expenditures-{$filters['month']}.xlsx",
        );
    }

    public function showExpenditure(Request $request, Expenditure $expenditure): Response
    {
        $this->authorize('view', $expenditure);

        $expenditure->load(['category', 'user', 'voucher', 'corrects', 'voidedBy']);
        $user = $request->user();
        $open = ! $this->months->isClosed($expenditure->period());
        $voucher = $expenditure->voucher;

        return Inertia::render('Resources/Expenditures/Show', [
            'expenditure' => [
                ...$this->formatExpenditure($expenditure),
                'category_uuid' => $expenditure->category?->uuid,
                'void_reason' => $expenditure->void_reason,
                'voided_by_name' => $expenditure->voidedBy?->name,
                'voided_at' => $expenditure->voided_at?->toIso8601String(),
                'corrects' => $expenditure->corrects ? ['uuid' => $expenditure->corrects->uuid, 'spent_at' => $expenditure->corrects->spent_at?->toDateString()] : null,
                'corrections' => $voucher
                    ? Expenditure::query()->where('voucher_id', $voucher->id)->whereKeyNot($expenditure->id)->orderBy('id')->get()
                        ->map(fn (Expenditure $row): array => ['uuid' => $row->uuid, 'amount' => (string) $row->amount, 'spent_at' => $row->spent_at?->toDateString(), 'source' => $row->source, 'description' => $row->description])
                        ->values()->all()
                    : [],
            ],
            'voucher' => $voucher ? [
                'uuid' => $voucher->uuid,
                'number' => $voucher->number,
                'status' => $voucher->status,
                'payee_name' => $voucher->payee_name,
                'received_by_name' => $voucher->received_by_name,
                'amount' => (string) $voucher->amount,
            ] : null,
            'month_closed' => ! $open,
            'history' => $this->history($expenditure),
            'categories' => $this->categoryOptions(),
            'can' => [
                'update' => $open && ! $expenditure->isVoid() && $user->can('update', $expenditure),
                'void' => $open && ! $expenditure->isVoid() && $user->can('void', $expenditure),
                'correct' => $voucher !== null && $user->can('correct', $voucher),
            ],
        ]);
    }

    public function updateExpenditure(UpdateExpenditureRequest $request, Expenditure $expenditure): RedirectResponse
    {
        $validated = $request->validated();

        $this->expenditures->edit($expenditure, collect($validated)->except('reason')->all(), $validated['reason']);

        return back()->with('success', 'Expenditure updated.');
    }

    public function voidExpenditure(VoidExpenditureRequest $request, Expenditure $expenditure): RedirectResponse
    {
        $this->expenditures->void($expenditure, $request->user(), $request->validated('reason'));

        return back()->with('success', 'Expenditure voided. It stays on record but no longer counts.');
    }

    public function closeMonth(Request $request, string $period): RedirectResponse
    {
        $this->authorize('manageMonths', Expenditure::class);

        $this->months->close($period, $request->user());

        return back()->with('success', $this->months->label($period).' closed.');
    }

    public function reopenMonth(ReopenExpenditureMonthRequest $request, string $period): RedirectResponse
    {
        $this->months->reopen($period, $request->user(), $request->validated('reason'));

        return back()->with('success', $this->months->label($period).' reopened. Close it again once the fix is in.');
    }

    public function categories(): Response
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        $categories = $this->categories->list();

        return Inertia::render('Resources/Categories', [
            'categories' => $categories->map(fn (ExpenseCategory $category): array => [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'icon' => $category->icon,
                'is_active' => $category->is_active,
                'sort_order' => $category->sort_order,
            ])->values(),
        ]);
    }

    public function storeCategory(StoreExpenseCategoryRequest $request): RedirectResponse
    {
        $this->categories->create($request->validated());

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(UpdateExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        $this->categories->update($category, $request->validated());

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(ExpenseCategory $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $this->categories->deactivate($category);

        return back()->with('success', 'Category deactivated.');
    }

    /**
     * @return array{month: string, category_uuid?: string, source?: string, status?: string, user_uuid?: string}
     */
    private function indexFilters(Request $request): array
    {
        $month = (string) $request->query('month', '');

        return array_filter([
            'month' => BusinessTimezone::isValidPeriod($month) ? $month : BusinessTimezone::currentPeriod(),
            'category_uuid' => $request->query('category_uuid'),
            'source' => $request->query('source'),
            'status' => $request->query('status'),
            'user_uuid' => $request->query('user_uuid'),
        ], fn ($value): bool => is_string($value) && $value !== '');
    }

    /**
     * @return array{closed: bool, closed_at: ?string, closed_by_name: ?string, reopened_at: ?string, reopened_by_name: ?string, reopen_reason: ?string, can_close: bool}
     */
    private function shapeMonth(string $month, ?ExpenditureMonth $row): array
    {
        return [
            'closed' => (bool) $row?->isClosed(),
            'closed_at' => $row?->closed_at?->toIso8601String(),
            'closed_by_name' => $row?->closed_at !== null ? ($row->closedBy?->name ?? 'Automatic close') : null,
            'reopened_at' => $row?->reopened_at?->toIso8601String(),
            'reopened_by_name' => $row?->reopenedBy?->name,
            'reopen_reason' => $row?->reopen_reason,
            'can_close' => ! $row?->isClosed() && $month < BusinessTimezone::currentPeriod(),
        ];
    }

    /**
     * Months with spending or a close record, newest first, plus the
     * current month — the month picker / archive.
     *
     * @return list<array{month: string, closed: bool}>
     */
    private function archiveMonths(): array
    {
        $withSpending = Expenditure::query()->selectRaw("distinct to_char(spent_at, 'YYYY-MM') as month")->pluck('month');
        $closed = ExpenditureMonth::query()->pluck('closed_at', 'period');

        return $withSpending->merge($closed->keys())->push(BusinessTimezone::currentPeriod())
            ->unique()->sortDesc()->take(36)->values()
            ->map(fn (string $month): array => ['month' => $month, 'closed' => ($closed[$month] ?? null) !== null])
            ->all();
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    private function recordersFor(string $month): array
    {
        [$from, $to] = BusinessTimezone::periodDates($month);
        $ids = Expenditure::query()->whereBetween('spent_at', [$from, $to])->distinct()->pluck('user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['uuid', 'name'])
            ->map(fn (User $user): array => ['uuid' => $user->uuid, 'name' => $user->name])->values()->all();
    }

    /**
     * Every category, inactive ones included — a past month may hold
     * spending in a category since deactivated.
     *
     * @return list<array{uuid: string, name: string, is_active: bool}>
     */
    private function categoryOptions(): array
    {
        return $this->categories->list()
            ->map(fn (ExpenseCategory $category): array => ['uuid' => $category->uuid, 'name' => $category->name, 'is_active' => (bool) $category->is_active])
            ->values()
            ->all();
    }

    /**
     * The expenditure's audit trail as a timeline: who changed what, when,
     * and why (edit_reason / void_reason travel with each change).
     *
     * @return list<array{action: string, at: ?string, user_name: ?string, impersonator_name: ?string, changes: list<array{field: string, from: mixed, to: mixed}>, reason: ?string}>
     */
    private function history(Expenditure $expenditure): array
    {
        $logs = AuditLog::query()->with(['user', 'impersonator'])
            ->where('table_name', 'expenditures')
            ->where('record_id', $expenditure->id)
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $categoryNames = ExpenseCategory::query()->pluck('name', 'id');
        $tracked = ['category_id' => 'Category', 'description' => 'Description', 'notes' => 'Notes', 'status' => 'Status', 'amount' => 'Amount'];

        return $logs->map(function (AuditLog $log) use ($categoryNames, $tracked): array {
            $old = $log->old_values ?? [];
            $new = $log->new_values ?? [];
            $changes = [];

            if ($log->action === 'update') {
                foreach ($tracked as $field => $label) {
                    if (($old[$field] ?? null) != ($new[$field] ?? null)) {
                        $display = fn ($value) => $field === 'category_id' ? ($categoryNames[$value] ?? $value) : $value;
                        $changes[] = ['field' => $label, 'from' => $display($old[$field] ?? null), 'to' => $display($new[$field] ?? null)];
                    }
                }
            }

            return [
                'action' => $log->action,
                'at' => $log->created_at?->toIso8601String(),
                'user_name' => $log->user?->name ?? 'System',
                'impersonator_name' => $log->impersonator?->name,
                'changes' => $changes,
                'reason' => $log->action === 'update' ? (($new['status'] ?? null) === 'void' && ($old['status'] ?? null) !== 'void' ? ($new['void_reason'] ?? null) : ($new['edit_reason'] ?? null)) : null,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatExpenditure(Expenditure $expenditure): array
    {
        return [
            'uuid' => $expenditure->uuid,
            'category_name' => $expenditure->category?->name,
            'amount' => (string) $expenditure->amount,
            'description' => $expenditure->description,
            'spent_at' => $expenditure->spent_at?->toDateString(),
            'notes' => $expenditure->notes,
            'source' => $expenditure->source,
            'status' => $expenditure->status,
            'relates_to_period' => $expenditure->relates_to_period,
            'recorded_offline' => (bool) $expenditure->recorded_offline,
            'recorded_by_name' => $expenditure->user?->name,
            'voucher' => $expenditure->voucher ? ['uuid' => $expenditure->voucher->uuid, 'number' => $expenditure->voucher->number] : null,
            'created_at' => $expenditure->created_at?->toIso8601String(),
        ];
    }
}
