<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\ExpenditureData;
use App\Exports\ExpenseRegisterExport;
use App\Http\Requests\ExpenditureDecisionRequest;
use App\Http\Requests\StoreExpenditureRequest;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Http\Requests\UpdateExpenditureRequest;
use App\Http\Requests\UpdateExpenseCategoryRequest;
use App\Http\Resources\ExpenditureResource;
use App\Models\AuditLog;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExpenditureService;
use App\Services\ExpenseCategoryService;
use App\Services\ExpenseControlService;
use App\Services\ResourcesDashboardService;
use App\Services\SupplierService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Web (session-auth, Inertia) counterpart of Api\ExpenditureController,
 * Api\ExpenseCategoryController and Api\ResourcesDashboardController — same
 * ExpenditureService/ExpenseCategoryService/ResourcesDashboardService,
 * same FormRequests/Policies, just rendering Inertia pages and
 * redirecting-with-flash instead of returning JSON. See PaymentController's
 * class doc for why web controllers never call the JSON /api/v1/*
 * endpoints. Kept as a single controller (rather than split like the Api
 * namespace) per this module's task spec — dashboard + expenditures +
 * categories are one cohesive "Resources" admin area from the web panel's
 * point of view.
 */
class ResourceController extends Controller
{
    public function __construct(
        private readonly ExpenditureService $expenditures,
        private readonly ExpenseCategoryService $categories,
        private readonly ResourcesDashboardService $dashboard,
        private readonly ExpenseControlService $controls,
        private readonly SupplierService $suppliers,
        private readonly TenantContext $context,
    ) {}

    public function dashboard(Request $request): Response
    {
        $this->authorize('viewDashboard', Expenditure::class);

        $period = $request->query('period');

        $data = $this->dashboard->dashboard($period !== null ? (string) $period : null);

        return Inertia::render('Resources/Dashboard', $data);
    }

    public function expenditures(Request $request): Response
    {
        $this->authorize('viewAny', Expenditure::class);

        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));

        $filters = $request->only(['category_uuid', 'from', 'to', 'status', 'supplier_uuid', 'service_uuid', 'user_uuid']);

        $expenditures = $this->expenditures->list($filters, $perPage);

        $raw = $expenditures->toArray();

        return Inertia::render('Resources/Expenditures/Index', [
            'expenditures' => [
                'data' => ExpenditureResource::collection($expenditures->getCollection())->resolve($request),
                'links' => $raw['links'],
                'meta' => [
                    'current_page' => $raw['current_page'],
                    'per_page' => $raw['per_page'],
                    'total' => $raw['total'],
                    'last_page' => $raw['last_page'],
                ],
            ],
            'filters' => [
                'category_uuid' => $filters['category_uuid'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'status' => $filters['status'] ?? null,
                'supplier_uuid' => $filters['supplier_uuid'] ?? null,
                'service_uuid' => $filters['service_uuid'] ?? null,
            ],
            // One grouped query for the status tabs.
            'status_counts' => Expenditure::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'categories' => $this->activeCategoryOptions(),
            'suppliers' => Supplier::query()->where('status', '!=', Supplier::STATUS_INACTIVE)->orderBy('name')->get(['uuid', 'name'])->toArray(),
            'services' => $this->serviceOptions(),
        ]);
    }

    /**
     * Excel register of every expense in a date range (default: this
     * month), for the accountant and the investors.
     */
    public function exportExpenditures(Request $request): BinaryFileResponse
    {
        $this->authorize('viewDashboard', Expenditure::class);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $validated['from'] ?? Carbon::now()->startOfMonth()->toDateString();
        $to = $validated['to'] ?? Carbon::now()->endOfMonth()->toDateString();

        return Excel::download(new ExpenseRegisterExport($from, $to), "expenses-{$from}-to-{$to}.xlsx");
    }

    public function showExpenditure(Request $request, Expenditure $expenditure): Response
    {
        $this->authorize('view', $expenditure);

        $expenditure->load(['category', 'user', 'supplier', 'service', 'branch', 'firstApprover', 'approver', 'rejecter', 'voider', 'recurringExpense']);
        $user = $request->user();

        return Inertia::render('Resources/Expenditures/Show', [
            'expenditure' => (new ExpenditureResource($expenditure))->resolve($request),
            'branch_name' => $expenditure->branch?->name,
            'history' => $this->history($expenditure),
            'can' => [
                'update' => $user->can('update', $expenditure),
                'attach_receipt' => $expenditure->isPending() && $user->can('attachReceipt', $expenditure),
                'approve' => $expenditure->isPending()
                    && $expenditure->status !== Expenditure::STATUS_PENDING_EVIDENCE
                    && $expenditure->user_id !== $user->id
                    && $expenditure->first_approved_by !== $user->id
                    && $user->can('approve', $expenditure),
                'reject' => $expenditure->isPending() && $expenditure->user_id !== $user->id && $user->can('reject', $expenditure),
                'void' => $expenditure->status !== Expenditure::STATUS_VOID && $user->can('void', $expenditure),
            ],
            'categories' => $this->activeCategoryOptions(),
            'suppliers' => Supplier::query()->where('status', '!=', Supplier::STATUS_INACTIVE)->orderBy('name')->get(['uuid', 'name'])->toArray(),
            'services' => $this->serviceOptions(),
        ]);
    }

    public function createExpenditure(Request $request): Response
    {
        $this->authorize('create', Expenditure::class);

        return Inertia::render('Resources/Expenditures/Create', [
            'categories' => $this->activeCategoryOptions(),
            'suppliers' => Supplier::query()->where('status', '!=', Supplier::STATUS_INACTIVE)->orderBy('name')->get(['uuid', 'name', 'status'])->toArray(),
            'services' => $this->serviceOptions(),
            'limits' => $this->controls->limits(),
            // "Repeat last" — this person's most recent expense.
            'last' => ($last = Expenditure::query()->with(['category', 'supplier', 'service'])->where('user_id', $request->user()->id)->latest('id')->first()) === null
                ? null
                : (new ExpenditureResource($last))->resolve($request),
        ]);
    }

    public function storeExpenditure(StoreExpenditureRequest $request): RedirectResponse
    {
        $expenditure = $this->expenditures->create(
            ExpenditureData::fromArray($request->validated()),
            $request->user()->id,
            $request->file('receipt'),
            $request->receiptMeta(),
        );

        return redirect()->route('resources.expenditures.show', $expenditure)->with('success', $this->statusMessage($expenditure));
    }

    public function updateExpenditure(UpdateExpenditureRequest $request, Expenditure $expenditure): RedirectResponse
    {
        $expenditure = $this->expenditures->update($expenditure, ExpenditureData::fromArray($request->validated()));

        return back()->with('success', 'Expense updated. '.$this->statusMessage($expenditure));
    }

    public function attachReceipt(Request $request, Expenditure $expenditure): RedirectResponse
    {
        $this->authorize('attachReceipt', $expenditure);

        $request->validate(['receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:6144']]);

        $expenditure = $this->expenditures->attachReceipt($expenditure, $request->file('receipt'), ['source' => 'upload']);

        return back()->with('success', 'Receipt added. '.$this->statusMessage($expenditure));
    }

    public function receipt(Expenditure $expenditure): StreamedResponse
    {
        $this->authorize('view', $expenditure);

        return $this->expenditures->receiptResponse($expenditure);
    }

    public function approveExpenditure(Request $request, Expenditure $expenditure): RedirectResponse
    {
        $this->authorize('approve', $expenditure);

        $this->expenditures->approve($expenditure, $request->user());

        return back()->with('success', $expenditure->status === Expenditure::STATUS_PENDING_SECOND_APPROVAL
            ? 'First approval given — the owner gives the second.'
            : 'Expense approved.');
    }

    public function rejectExpenditure(ExpenditureDecisionRequest $request, Expenditure $expenditure): RedirectResponse
    {
        $this->authorize('reject', $expenditure);

        $this->expenditures->reject($expenditure, $request->user(), $request->validated('reason'));

        return back()->with('success', 'Expense rejected.');
    }

    public function voidExpenditure(ExpenditureDecisionRequest $request, Expenditure $expenditure): RedirectResponse
    {
        $this->authorize('void', $expenditure);

        $this->expenditures->void($expenditure, $request->user(), $request->validated('reason'));

        return back()->with('success', 'Expense voided. It stays on record but no longer counts.');
    }

    /**
     * The approvals inbox: everything this person can decide on now.
     */
    public function approvals(Request $request): Response
    {
        $this->authorize('viewAny', Expenditure::class);

        $items = $this->expenditures->awaitingDecisionBy(
            $request->user(),
            $this->context->can('expenditures.approve'),
            $this->context->can('expenditures.approve_large'),
        );

        return Inertia::render('Resources/Approvals', [
            'items' => ExpenditureResource::collection($items)->resolve($request),
            'can_approve' => $this->context->can('expenditures.approve') || $this->context->can('expenditures.approve_large'),
            'pending_suppliers' => $this->context->can('suppliers.manage')
                ? Supplier::query()->with('creator')->where('status', Supplier::STATUS_PENDING)->where('created_by', '!=', $request->user()->id)->orderBy('created_at')->get()
                    ->map(fn (Supplier $supplier): array => [
                        'uuid' => $supplier->uuid,
                        'name' => $supplier->name,
                        'phone' => $supplier->phone,
                        'momo_number' => $supplier->momo_number,
                        'created_by' => $supplier->creator?->name,
                        'staff_matches' => $this->suppliers->staffMatches($supplier),
                    ])->values()->all()
                : [],
        ]);
    }

    private function statusMessage(Expenditure $expenditure): string
    {
        return match ($expenditure->status) {
            Expenditure::STATUS_APPROVED => 'Recorded and approved.',
            Expenditure::STATUS_PENDING_EVIDENCE => 'Saved — add the receipt photo so it can be approved.',
            Expenditure::STATUS_PENDING_APPROVAL => 'Saved — waiting for a manager to approve it.',
            Expenditure::STATUS_PENDING_SECOND_APPROVAL => 'Saved — waiting for the owner\'s approval.',
            default => 'Saved.',
        };
    }

    /**
     * The expense's own trail, from the immutable audit log.
     *
     * @return list<array{at: ?string, action: string, by: ?string, changes: array<string, mixed>}>
     */
    private function history(Expenditure $expenditure): array
    {
        $logs = AuditLog::query()
            ->where('table_name', 'expenditures')
            ->where('record_uuid', $expenditure->uuid)
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        // One lookup for every actor in the trail.
        $names = User::query()->whereIn('id', $logs->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return $logs->map(fn (AuditLog $log): array => [
            'at' => $log->created_at?->toIso8601String(),
            'action' => (string) $log->action,
            'by' => $log->user_id !== null ? $names->get($log->user_id) : null,
            'changes' => collect($log->new_values ?? [])
                ->only(['status', 'amount', 'category_id', 'spent_at', 'supplier_id', 'payment_method', 'payment_ref', 'receipt_path', 'rejection_reason', 'void_reason'])
                ->all(),
        ])->values()->all();
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    private function serviceOptions(): array
    {
        return Service::query()->where('active', true)->orderBy('sort_order')->orderBy('name')->get(['uuid', 'name'])->toArray();
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
     * @return array<int, array{uuid: string, name: string}>
     */
    private function activeCategoryOptions(): array
    {
        return $this->categories->list(true)
            ->map(fn (ExpenseCategory $category): array => ['uuid' => $category->uuid, 'name' => $category->name])
            ->values()
            ->all();
    }
}
