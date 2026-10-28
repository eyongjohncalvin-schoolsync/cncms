<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\CustomerData;
use App\DataTransferObjects\InstallationData;
use App\Exports\CustomerImportTemplateExport;
use App\Http\Requests\ArchiveCustomerRequest;
use App\Http\Requests\BulkUpdateCustomerBillRequest;
use App\Http\Requests\DisconnectCustomerRequest;
use App\Http\Requests\ImportCustomersRequest;
use App\Http\Requests\PreviewCustomerInstallationRequest;
use App\Http\Requests\ReconnectCustomerRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\SuspendCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\ArrearsAdjustment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\CustomerSubscription;
use App\Models\FieldInstallation;
use App\Models\FreeMonthGrant;
use App\Models\InstallationOffer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\TenantUser;
use App\Models\Upload;
use App\Models\User;
use App\Models\Zone;
use App\Services\ArrearsAdjustmentService;
use App\Services\CustomerChargeService;
use App\Services\CustomerCoverageService;
use App\Services\CustomerImportService;
use App\Services\CustomerInstallationService;
use App\Services\CustomerRelocationService;
use App\Services\CustomerService;
use App\Services\CustomerStatusService;
use App\Services\FieldInstallationService;
use App\Services\FreeMonthGrantService;
use App\Services\InstallationOfferService;
use App\Services\InvoiceService;
use App\Services\LateRegistrationService;
use App\Services\ManuscriptRunLockService;
use App\Services\ManuscriptService;
use App\Services\ZoneService;
use App\Support\Billing\ConnectionMonth;
use App\Support\BranchPrices;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Web (session-auth, Inertia) counterpart to Api\CustomerController. Reuses
 * the same CustomerService and the same StoreCustomerRequest/
 * UpdateCustomerRequest Form Requests (so validation and the create/update
 * authorization gate live in exactly one place), but renders Inertia pages
 * and redirects with flash session data instead of returning JSON — see
 * AuthController's doc comment for why web pages never call /api/v1/*
 * directly.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerStatusService $statuses,
        private readonly ManuscriptService $manuscripts,
        private readonly ZoneService $zones,
        private readonly CustomerImportService $customerImports,
        private readonly ArrearsAdjustmentService $arrearsAdjustments,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        // ?archived=1 is a secondary view (mirrors /disconnections?eligible=1),
        // not a status filter — archived is orthogonal to active/passive/
        // disconnected/suspended. When on, the list shows ONLY archived
        // customers, each with a Restore action.
        $archived = $request->boolean('archived');
        // ?pending_activation=1: field registrations awaiting the office
        // (installation phase 3) — the activation queue.
        $pendingActivation = ! $archived && $request->boolean('pending_activation');

        $filters = $request->only(['zone_uuid', 'status', 'level', 'search']);

        // Closures: the background-import progress poll (a partial reload of
        // background_import alone) then never loads the list.
        return Inertia::render('Customers/Index', [
            'archived_view' => $archived,
            'pending_activation_view' => $pendingActivation,
            'pending_activation_count' => fn (): int => app(FieldInstallationService::class)->pendingCount(),
            'customers' => function () use ($filters, $archived, $pendingActivation): array {
                $paginator = $this->customers->list([...$filters, 'archived' => $archived, 'pending_activation' => $pendingActivation], 15);

                return [
                    'data' => collect($paginator->items())
                        ->map(fn (Customer $customer) => $this->shapeCustomer($customer))
                        ->all(),
                    'links' => $paginator->linkCollection()->toArray(),
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                        'last_page' => $paginator->lastPage(),
                    ],
                ];
            },
            'zones' => fn (): array => $this->zonesForSelect(),
            // A big import running in the background, then its report.
            // A closure: the page's progress poll asks for this prop alone.
            'background_import' => fn (): ?array => $this->backgroundImport($request->user()),
            'filters' => [
                'zone_uuid' => $filters['zone_uuid'] ?? null,
                'status' => $filters['status'] ?? null,
                'level' => $filters['level'] ?? null,
                'search' => $filters['search'] ?? null,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Customer::class);

        return Inertia::render('Customers/Create', [
            'zones' => $this->zonesForSelect(),
            'service_catalogue' => $this->serviceCatalogue(),
            // "Installation & first payment" (docs/plans/installation-and-promotions.md, phase 2).
            'installation' => [
                'offers' => app(InstallationOfferService::class)->availableForForm(),
                'max_free_months' => FreeMonthGrantService::maxMonths(),
                'can_manage' => $this->context->can('installations.manage'),
                'can_take_payment' => $this->context->can('payments.create'),
                'today' => BusinessTimezone::today(),
                // "Connected by" (auditing a late registration).
                'staff' => $this->staffForSelect(),
            ],
        ]);
    }

    /**
     * Staff who may have connected a customer — active members of this
     * company, of the viewer's branch (or headquarters' unbranched staff)
     * when they are fenced to one.
     *
     * @return list<array{uuid: string, name: string}>
     */
    private function staffForSelect(): array
    {
        $members = TenantUser::query()
            ->when($this->context->isFenced(), fn ($query) => $query->where(fn ($inner) => $inner->where('branch_id', $this->context->branchId)->orWhereNull('branch_id')))
            ->pluck('user_id');

        return User::query()
            ->whereIn('id', $members)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['uuid', 'name'])
            ->map(fn (User $user): array => ['uuid' => (string) $user->uuid, 'name' => (string) $user->name])
            ->all();
    }

    /**
     * One save, one transaction (installation phase 2, item 5): the
     * customer, the installation charge and payment, an optional advance
     * and the free months — see App\Services\CustomerInstallationService.
     * With money taken the office lands on the new customer's page, where
     * each payment's receipt is.
     */
    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $installation = InstallationData::fromArray($request->validated());

        $result = app(CustomerInstallationService::class)->create(CustomerData::fromArray($request->validated()), $installation, $request->user());

        if ($installation->isEmpty()) {
            return redirect()->route('customers.index')->with('success', 'Customer created.');
        }

        return redirect()->route('customers.show', $result['customer'])->with('success', 'Customer created.'.($result['payments'] !== [] ? ' Receipts issued for '.count($result['payments']).' payment(s).' : ''));
    }

    /**
     * POST /customers/installation-preview — the add-customer form's live
     * line ("Collect now: 17,500 · TV free Nov–Dec · first bill Jan 2027"),
     * the advance amount, what needs approval and possible duplicates.
     * Writes nothing.
     */
    public function previewInstallation(PreviewCustomerInstallationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            return response()->json(app(CustomerInstallationService::class)->preview(
                CustomerData::fromArray(['services' => $validated['services'] ?? []])->services ?? [],
                InstallationData::fromArray($validated),
                $validated['name'] ?? null,
                $validated['phone'] ?? null,
            ));
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Bulk customer import from an .xlsx spreadsheet — see
     * App\Services\CustomerImportService. This is the primary onboarding
     * path for a new cable operator's existing customers (and their
     * pre-existing arrears via the `others` column — see
     * App\Services\ManuscriptCalculator's class doc comment). Reports a
     * partial success (some created, some skipped) rather than treating
     * any single row failure as fatal for the whole file, mirroring
     * storeBulk-style bulk actions elsewhere in the app (e.g.
     * PaymentController::storeBulk()).
     */
    public function import(ImportCustomersRequest $request): RedirectResponse
    {
        $result = $this->customerImports->import($request->file('file'), $request->user(), TenantContext::currentBranchId());

        if ($result['pending']) {
            return redirect()->route('customers.index')->with('success', "Importing {$result['rows']} rows in the background — the report will appear on this page when it's done.");
        }

        $succeededCount = count($result['succeeded']);
        $failedCount = count($result['failed']);

        $message = $succeededCount === 1 ? '1 customer imported.' : "{$succeededCount} customers imported.";

        if ($failedCount > 0) {
            $message .= ' '.($failedCount === 1 ? '1 row failed — see the import report below.' : "{$failedCount} rows failed — see the import report below.");
        }

        return redirect()->route('customers.index')
            ->with($succeededCount > 0 ? 'success' : 'error', $message)
            ->with('import', [
                'type' => 'customers',
                'succeeded_count' => $succeededCount,
                'failed_count' => $failedCount,
                'failed' => collect($result['failed'])
                    ->map(fn (string $reason, int $row): array => ['row' => $row, 'reason' => $reason])
                    ->values()
                    ->all(),
            ]);
    }

    /**
     * GET /customers/import/template — downloads a blank
     * customer_upload_main.xlsx with the exact header row import() expects
     * (App\Imports\CustomersImport::COLUMNS, the single source of truth
     * both this template and the real import read from) plus a "Valid
     * Zones" reference sheet, so an operator can fill in a correctly
     * formatted spreadsheet before uploading instead of guessing the
     * layout. Same 'create' gate as the upload itself and as a manual "Add
     * Customer" form (CustomerPolicy::create()).
     */
    /**
     * The viewer's latest background import (one too big to run while they
     * waited — CustomerImportService::SYNC_ROW_LIMIT) from the last day:
     * its progress, then the same report a small import flashes.
     *
     * @return array{status: string, file_name: string, total_rows: ?int, report: array{type: string, succeeded_count: int, failed_count: int, failed: list<array{row: int, reason: string}>}}|null
     */
    private function backgroundImport(User $user): ?array
    {
        $upload = Upload::query()
            ->where('type', 'customers')
            ->where('imported_by', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->where(fn ($query) => $query->whereIn('status', ['pending', 'processing'])->orWhere('total_rows', '>', CustomerImportService::SYNC_ROW_LIMIT))
            ->latest('id')
            ->first();

        if ($upload === null) {
            return null;
        }

        return [
            'status' => $upload->status,
            'file_name' => $upload->file_name,
            'total_rows' => $upload->total_rows,
            'report' => [
                'type' => 'customers',
                'succeeded_count' => (int) $upload->succeeded_count,
                'failed_count' => (int) $upload->failed_count,
                'failed' => collect($upload->errors ?? [])
                    ->map(fn (string $reason, int|string $row): array => ['row' => (int) $row, 'reason' => $reason])
                    ->values()
                    ->all(),
            ],
        ];
    }

    public function importTemplate(): BinaryFileResponse
    {
        $this->authorize('create', Customer::class);

        return Excel::download(
            new CustomerImportTemplateExport($this->zones->all()),
            'customer_import_template.xlsx',
        );
    }

    public function show(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        // withTrashed: an archived customer's detail page stays viewable
        // (read-only, with a Restore banner) — the concrete payoff of
        // "archived, not deleted". Every other customer route keeps the
        // default binding and 404s a trashed uuid.
        $customer = $this->customers->findOrFail($customer->uuid, withTrashed: true);

        if ($customer->trashed()) {
            $customer->loadMissing('archivedBy');
        }

        return Inertia::render('Customers/Show', [
            'customer' => $this->shapeCustomerDetail($customer),
            // Monthly bills (invoices) with their paid status — newest first.
            'bills' => app(InvoiceService::class)->billsFor($customer, 12),
        ]);
    }

    /**
     * PATCH /customers/{customer}/archive. Body: {name: string (must match
     * the customer's name exactly — the type-to-confirm gate), reason:
     * string}. Archives (soft-deletes) a customer with billing history so
     * the history stays auditable; see App\Services\CustomerService::
     * archive() and CustomerPolicy::archive().
     */
    public function archive(ArchiveCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->archive($customer, $request->user()->id, $request->validated('reason'));

        return redirect()->route('customers.index')->with('success', "{$customer->name} archived. Their billing history is kept — restore them any time.");
    }

    /**
     * PATCH /customers/{customer}/restore. Binds a trashed customer
     * (->withTrashed() on the route). Brings an archived customer back into
     * the active register.
     */
    public function restore(Customer $customer): RedirectResponse
    {
        $this->authorize('restore', $customer);

        $this->customers->restore($customer);

        return redirect()->route('customers.show', $customer->uuid)->with('success', "{$customer->name} restored.");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function shapeArrearsAdjustments(Customer $customer): array
    {
        return $this->arrearsAdjustments->recentForCustomer($customer)
            ->map(fn (ArrearsAdjustment $adjustment): array => [
                'uuid' => $adjustment->uuid,
                'target_period' => $adjustment->target_period,
                'direction' => $adjustment->direction,
                'target' => $adjustment->target,
                'amount' => $adjustment->amount,
                'reason_category' => $adjustment->reason_category,
                'reason_note' => $adjustment->reason_note,
                'status' => $adjustment->status,
                'requested_by_name' => $adjustment->requestedBy?->name,
                'approved_by_name' => $adjustment->approvedBy?->name,
                'second_approved_by_name' => $adjustment->secondApprovedBy?->name,
                'rejection_reason' => $adjustment->rejection_reason,
                'created_at' => $adjustment->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        $customer->loadMissing(['zone', 'subscriptions.service', 'subscriptions.serviceVariant']);

        return Inertia::render('Customers/Edit', [
            'customer' => $this->shapeCustomer($customer),
            'zones' => $this->zonesForSelect(),
            'service_catalogue' => $this->serviceCatalogue($customer),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->update($customer, CustomerData::fromArray($request->validated()));

        return redirect()->route('customers.index')->with('success', 'Customer updated.');
    }

    /**
     * POST /customers/bulk-update-bill/preview. Dry run — computes what
     * bulkUpdateBill() below WOULD change without writing anything, so
     * Customers/Index.tsx's bulk "Update Bills" modal can show a real
     * current->new table before the office worker confirms. Returns plain
     * JSON (not an Inertia response) since it backs a modal fetch, not a
     * page navigation — same pattern as lastPayment() above.
     */
    public function previewBulkUpdateBill(BulkUpdateCustomerBillRequest $request): JsonResponse
    {
        $result = $this->customers->previewBulkBillUpdate(
            $request->validated('customer_uuids'),
            $request->only(['zone_uuid', 'level', 'status', 'search']),
            $request->validated('mode'),
            (string) $request->validated('value'),
        );

        return response()->json($result);
    }

    /**
     * POST /customers/bulk-update-bill. Commits a bulk bill adjustment
     * across many customers at once — see App\Services\CustomerService::
     * bulkUpdateBill(), which shares its bcmath computation with
     * previewBulkUpdateBill() above so the two can never disagree. Reports
     * a partial success ("N customers' bills updated. M skipped — see
     * reasons below.") the same way PaymentController::storeBulk() and
     * DisconnectionsController::bulkRedirect() do.
     */
    public function bulkUpdateBill(BulkUpdateCustomerBillRequest $request): RedirectResponse
    {
        $result = $this->customers->bulkUpdateBill(
            $request->validated('customer_uuids'),
            $request->only(['zone_uuid', 'level', 'status', 'search']),
            $request->validated('mode'),
            (string) $request->validated('value'),
        );

        $updatedCount = count($result['updated']);
        $skippedCount = count($result['skipped']);

        $message = $updatedCount === 1 ? "1 customer's bill updated." : "{$updatedCount} customers' bills updated.";

        if ($skippedCount > 0) {
            $message .= ' '.($skippedCount === 1 ? '1 skipped — see reasons below.' : "{$skippedCount} skipped — see reasons below.");
        }

        return back()
            ->with($updatedCount > 0 ? 'success' : 'error', $message)
            ->with('bulkBillUpdateSkipped', array_values($result['skipped']));
    }

    /**
     * CustomerService::delete() throws a friendly ValidationException
     * (rather than a raw QueryException) when the customer has payment/
     * manuscript/message history protected by restrictOnDelete() — caught
     * here and flashed the same way as BranchController::destroy() handles
     * the equivalent zones.branch_id restriction, rather than left to
     * Inertia's default `errors` bag (a delete action isn't a form
     * submission tied to input fields, so a flash 'error' message is the
     * better fit here, matching the established convention).
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        try {
            $this->customers->delete($customer);
        } catch (ValidationException $e) {
            return redirect()->route('customers.index')->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('customers.index')->with('success', 'Customer deleted.');
    }

    /**
     * PATCH /customers/{customer}/disconnect. Policy ability: 'disconnect'
     * (super/admin/manager). Body: {note?: string}. This is the fast,
     * dedicated action for cutting off a non-paying customer — see
     * App\Services\CustomerStatusService::disconnect(), which is also the
     * exact method a future arrears-based disconnect-eligibility monitor
     * should call directly (Customer $customer, ?string $note = null): Customer.
     */
    public function disconnect(DisconnectCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->statuses->disconnect($customer, $request->validated('note'));

        return back()->with('success', "{$customer->name} disconnected.");
    }

    /**
     * PATCH /customers/{customer}/suspend. Policy ability: 'suspend'
     * (super/admin/manager). Body: {reason: tv_problem|poor_service|customer_request|
     * zone_transfer|other, note?: string (required when reason=other),
     * pause_prepaid?: bool (references/prepaid-pause-handling.md section 5
     * — defaults to true, the recommended/pre-selected choice, when the
     * frontend omits it)}.
     */
    public function suspend(SuspendCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->statuses->suspend(
            $customer,
            $request->validated('reason'),
            $request->validated('note'),
            $request->boolean('pause_prepaid', true),
        );

        return back()->with('success', "{$customer->name} suspended.");
    }

    /**
     * PATCH /customers/{customer}/reconnect. Policy ability: 'reconnect'
     * (super/admin/manager). Body: {note?: string, include_fine?: bool
     * (2026-08 owner decision: admin-discretion opt-in, unchecked/false by
     * default, for EITHER 'disconnected' or 'suspended' — see
     * business-rules.md section 6, the 2,000 FCFA reconnection fine),
     * arrears_payment?: string (optional partial/full arrears payment,
     * single-customer reconnect only — see ReconnectCustomerRequest)}.
     */
    public function reconnect(ReconnectCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->statuses->reconnect(
            $customer,
            $request->validated('note'),
            $request->boolean('include_fine'),
            $request->validated('arrears_payment') !== null ? (string) $request->validated('arrears_payment') : null,
            $request->validated('method'),
            $request->validated('method_reference'),
        );

        return back()->with('success', "{$customer->name} reconnected.");
    }

    /**
     * Streams the customer's bill slip PDF, reusing the exact same
     * ManuscriptService::billData() that Api\BillController uses. Renders
     * whichever of the three bill templates (resources/views/pdf/bills/
     * {classic,compact,modern}.blade.php) the tenant has configured via
     * Settings > Bill Printing (Company::bill_template), falling back to
     * 'classic' for a tenant that hasn't set one yet or has a stale/invalid
     * value. This is a dedicated web route rather than a redirect to
     * /api/v1/bills/{uuid}/print because that API route requires Sanctum
     * bearer auth, which a browser session doesn't carry.
     */
    public function printBill(Request $request, Customer $customer): SymfonyResponse
    {
        $this->authorize('printBill', $customer);

        $period = $request->string('period')->value() ?: null;

        // A bill slip only ever prints for an ACTIVE customer — a
        // disconnected/suspended/passive customer is frozen with a 0
        // total_bill (owner decision, 2026-08). ManuscriptService::billData()
        // is the guard; catch its friendly ValidationException into a flash
        // 'error' and bounce back to the customer page, the same shape as
        // destroy()'s catch of CustomerService::delete().
        try {
            $data = $this->manuscripts->billData($customer, $period);
        } catch (ValidationException $e) {
            return redirect()->route('customers.show', $customer->uuid)
                ->with('error', collect($e->errors())->flatten()->first());
        }

        $template = $this->resolveBillTemplate($data['company'] ?? null);

        return Pdf::loadView('pdf.bills.show', [...$data, 'template' => $template])->stream("bill-{$customer->uuid}.pdf");
    }

    private function resolveBillTemplate(?Company $company): string
    {
        return in_array($company?->bill_template, Company::BILL_TEMPLATES, true)
            ? $company->bill_template
            : 'classic';
    }

    /**
     * GET /customers/{customer}/last-payment. Lightweight JSON lookup (not
     * an Inertia page) backing the "Record Payment" single-payment form's
     * info panel (resources/tsx/pages/Payments/Create.tsx) — it needs just
     * the selected customer's most recent payment, not the whole
     * Customers/Show page that show() above renders. Same 'view' policy
     * gate as viewing the customer at all (CustomerPolicy::view() — true
     * for anyone with tenant access). A tiny direct query against the
     * payments() relation is proportionate here rather than a new
     * Service/Repository method, the same "small dedicated lookup" call
     * SettingsCompanyController makes for its one-record settings form —
     * there's no reuse case for "one customer's latest payment" beyond
     * this single info panel.
     *
     * Response body: {"payment": {...}} or {"payment": null} when the
     * customer has no payments yet — wrapped under a `payment` key rather
     * than a bare top-level value, because Symfony's JsonResponse
     * constructor special-cases a null $data argument into an empty
     * ArrayObject (encoding to "[]", not the literal "null"), so a bare
     * `response()->json($payment ?: null)` can't actually represent the
     * "no payments" case as JSON null.
     */
    public function lastPayment(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $payment = $customer->payments()->subscription()->latest('created_at')->first();

        return response()->json([
            'payment' => $payment ? $this->shapePayment($payment) : null,
        ]);
    }

    /**
     * @return array<int, array{uuid: string, name: string, branch_name: ?string}>
     */
    private function zonesForSelect(): array
    {
        return $this->zones->all()
            ->map(fn (Zone $zone) => [
                'uuid' => $zone->uuid,
                'name' => $zone->name,
                'branch_name' => $zone->branchName(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeCustomer(Customer $customer): array
    {
        return [
            'uuid' => $customer->uuid,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'preferred_channel' => $customer->preferred_channel ?? 'whatsapp',
            'zone_uuid' => $customer->zone?->uuid,
            'zone_name' => $customer->zone?->name,
            'bill' => $customer->bill,
            'others' => $customer->others,
            'level' => $customer->level,
            'status' => $customer->status,
            // "pending" = registered in the field, awaiting the office (phase 3).
            'activation_status' => $customer->activation_status,
            'status_reason' => $customer->status_reason,
            'status_note' => $customer->status_note,
            'location' => $customer->location,
            // prepaid-pause-handling.md section 5: backs Customers/Show.tsx's
            // passive "Prepaid through..."/"...will resume on
            // reconnection"/"...still running" note near the status badge.
            'status_changed_at' => $customer->status_changed_at?->toISOString(),
            'prepaid_paused' => $customer->prepaid_paused,
            // Archiving (customer-deletion deliberation, 2026-08-29).
            // `has_billing_history` drives "Archive customer" vs "Delete
            // row" in the list; the archived_* fields are null unless the
            // customer is currently archived.
            'has_billing_history' => $this->resolveHasBillingHistory($customer),
            'archived_at' => $customer->deleted_at?->toISOString(),
            'archived_by_name' => $customer->relationLoaded('archivedBy') ? $customer->archivedBy?->name : null,
            'archived_reason' => $customer->archived_reason,
            // services.md section 6 — the customer's ticked services/options,
            // feeding both the Edit form's prefill and the Show page's
            // "Services" card. Omitted (not just empty) when `subscriptions`
            // wasn't eager-loaded, matching this codebase's existing
            // relationLoaded() convention above rather than lazy-loading here.
            'services' => $customer->relationLoaded('subscriptions')
                ? $customer->subscriptions->map(fn (CustomerSubscription $row): array => [
                    'service_uuid' => $row->service->uuid,
                    'service_name' => $row->service->name,
                    'service_variant_uuid' => $row->serviceVariant?->uuid,
                    'service_variant_name' => $row->serviceVariant?->name,
                    'price' => $row->price,
                ])->values()->all()
                : null,
        ];
    }

    /**
     * The tick-list a customer add/edit form renders: every active service
     * (with its active variants), PLUS — when editing $customer — any
     * service/variant they already hold even if it has since gone inactive
     * (services.md section 8: shown ticked with a muted "(inactive)" tag
     * rather than silently dropped off the form). Omitted from the Create
     * form's $customer-less call.
     *
     * @return list<array<string, mixed>>
     */
    private function serviceCatalogue(?Customer $customer = null): array
    {
        $heldServiceIds = $customer?->relationLoaded('subscriptions')
            ? $customer->subscriptions->pluck('service_id')->unique()->all()
            : [];
        $heldVariantIds = $customer?->relationLoaded('subscriptions')
            ? $customer->subscriptions->pluck('service_variant_id')->filter()->unique()->all()
            : [];
        // Branches v2 D4: the customer's branch's prices; for a new customer, the branch being worked in.
        $prices = $customer !== null ? BranchPrices::for($customer->branch_id) : BranchPrices::current();

        return Service::query()
            ->where(fn ($query) => $query->where('active', true)->when(
                $heldServiceIds !== [],
                fn ($inner) => $inner->orWhereIn('id', $heldServiceIds)
            ))
            ->ordered()
            ->with(['variants' => fn ($query) => $query
                ->where(fn ($inner) => $inner->where('active', true)->when(
                    $heldVariantIds !== [],
                    fn ($innermost) => $innermost->orWhereIn('id', $heldVariantIds)
                ))
                ->ordered()])
            ->get()
            ->map(fn (Service $service): array => [
                'uuid' => $service->uuid,
                'name' => $service->name,
                'description' => $service->description,
                'price' => $prices->service($service),
                // Quick picks besides the default; any other amount only for
                // whoever may type one, and never below the minimum.
                'price_options' => $service->price_options ?? [],
                'min_price' => $service->min_price,
                'is_default' => $service->is_default,
                'active' => $service->active,
                'variants' => $service->variants->map(fn (ServiceVariant $variant): array => [
                    'uuid' => $variant->uuid,
                    'name' => $variant->name,
                    'price' => $prices->variant($variant),
                    'active' => $variant->active,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * True if the customer has any payment/manuscript/message history that
     * archiving must preserve. Uses the withExists() attributes
     * (`*_exists`) the list query loads when they're present — one row, no
     * extra query — and falls back to a direct existence check on the
     * detail/edit path where withExists() didn't run.
     */
    private function resolveHasBillingHistory(Customer $customer): bool
    {
        $attributes = $customer->getAttributes();
        $withExistsRan = false;

        foreach (['payments_exists', 'manuscripts_exists', 'messages_exists'] as $attr) {
            if (! array_key_exists($attr, $attributes)) {
                continue;
            }

            $withExistsRan = true;

            if ($customer->getAttribute($attr)) {
                return true;
            }
        }

        return $withExistsRan ? false : $this->customers->hasBillingHistory($customer);
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeCustomerDetail(Customer $customer): array
    {
        return [
            ...$this->shapeCustomer($customer),
            'description' => $customer->description,
            'created_at' => $customer->created_at?->toISOString(),
            'manuscript' => $customer->latestManuscript ? [
                'uuid' => $customer->latestManuscript->uuid,
                'bill' => $customer->latestManuscript->bill,
                'total_arrears' => $customer->latestManuscript->total_arrears,
                'credit' => $customer->latestManuscript->credit,
                'total_bill' => $customer->latestManuscript->total_bill,
                'payment_expiration' => $customer->latestManuscript->payment_expiration?->toDateString(),
                'prepaid_months_remaining' => (int) $customer->latestManuscript->prepaid_months_remaining,
                'prepaid_rate' => $customer->latestManuscript->prepaid_rate,
                'free_value' => (string) $customer->latestManuscript->free_value,
                'period' => $customer->latestManuscript->period,
            ] : null,
            'recent_payments' => $customer->payments->map(fn (Payment $payment) => $this->shapePayment($payment))->all(),
            'arrears_adjustments' => $this->shapeArrearsAdjustments($customer),
            // Installation fees (docs/plans/installation-and-promotions.md):
            // a separate balance, never part of the bill's total or arrears.
            'installation' => $this->shapeInstallation($customer),
            // Relocation and re-installation fees — the same separate
            // balance, with the "Relocate" / "Re-install" actions.
            'one_off_charges' => $this->shapeOneOffCharges($customer),
            // Free months and the coverage line ("TV free until Dec ·
            // paid until Mar · first bill Apr") — installation phase 2.
            'free_months' => $this->shapeFreeMonths($customer),
            'coverage' => app(CustomerCoverageService::class)->summarize($customer),
            // A customer an agent registered in the field (phase 3): what
            // they proposed, the office's decision, and the activation form.
            'field_installation' => $this->shapeFieldInstallation($customer),
            // Gates the "Export full record" control in the page header
            // (docs/plans/customer-record-export.md) — a full unredacted
            // data dump, seeded super/admin only. The frontend also has the
            // shared `auth.user.permissions` list; this prop mirrors the
            // other `can_*` Show props (e.g. Payments/Show's
            // can_issue_receipt) for consistency.
            'can_export_record' => $this->context->can('customers.export_record'),
        ];
    }

    /**
     * The customer page's field registration block (installation phase 3),
     * null for a customer added at the office.
     *
     * @return array<string, mixed>|null
     */
    private function shapeFieldInstallation(Customer $customer): ?array
    {
        $field = FieldInstallation::query()
            ->with(['submittedBy', 'decidedBy', 'refundVoucher', 'payments.receipt'])
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->first();

        if ($field === null) {
            return null;
        }

        $user = request()->user();
        $proposal = $field->proposal;
        $services = Service::query()->pluck('name', 'uuid');
        $offers = app(InstallationOfferService::class)->availableForForm($customer->branch_id);
        $offerName = ($proposal['installation_offer_uuid'] ?? null) !== null
            ? InstallationOffer::query()->where('uuid', $proposal['installation_offer_uuid'])->value('name')
            : null;
        $currentPeriod = app(ManuscriptRunLockService::class)->currentPeriod();
        $connectedOn = (string) ($proposal['connected_on'] ?? $customer->connected_on?->toDateString() ?? BusinessTimezone::today());
        // Free months count from the connection month, even a closed one (ConnectionMonth).
        $installationMonth = ConnectionMonth::of(min($connectedOn, BusinessTimezone::today()));

        return [
            'uuid' => $field->uuid,
            'status' => $field->status,
            'submitted_by_name' => $field->submittedBy?->name,
            'collected_at' => $field->collected_at?->toIso8601String(),
            'synced_at' => $field->created_at?->toIso8601String(),
            'decided_by_name' => $field->decidedBy?->name,
            'decided_at' => $field->decided_at?->toIso8601String(),
            'decision_note' => $field->decision_note,
            'corrections' => $field->corrections,
            'possible_duplicates' => $field->possible_duplicates ?? [],
            'refund_voucher_number' => $field->refundVoucher?->number,
            'refund_voucher_status' => $field->refundVoucher?->status,
            'proposal' => [
                'offer_uuid' => $proposal['installation_offer_uuid'] ?? null,
                'offer_name' => $offerName,
                'fee' => $proposal['installation_fee'] ?? null,
                'paid' => $proposal['installation_paid'] ?? '0.00',
                'reason' => $proposal['installation_reason'] ?? null,
                'connected_on' => $proposal['connected_on'] ?? null,
                'service_plans' => array_map(fn (array $row): array => [
                    'service_uuid' => $row['service_uuid'],
                    'service_name' => (string) ($services[$row['service_uuid']] ?? 'Service'),
                    'free_from' => $row['free_from'] ?? null,
                    'free_months' => (int) ($row['free_months'] ?? 0),
                    'paid_months' => (int) ($row['paid_months'] ?? 0),
                ], $proposal['service_plans'] ?? []),
            ],
            'payments' => $field->payments->map(fn (Payment $payment): array => [
                'uuid' => $payment->uuid,
                'amount' => (string) $payment->amount,
                'purpose' => $payment->purpose,
                'verification_status' => $payment->verification_status,
                'receipt_number' => $payment->receipt?->receipt_number,
            ])->values()->all(),
            'can_decide' => $user->can('activate', $field),
            // The activation form's choices and starting values.
            'form' => [
                'zones' => $this->zonesForSelect(),
                'offers' => $offers,
                'max_free_months' => FreeMonthGrantService::maxMonths(),
                'installation_month' => $installationMonth,
                'current_month' => $currentPeriod,
                'today' => BusinessTimezone::today(),
                'staff' => $this->staffForSelect(),
                // Defaults to the agent who registered it in the field.
                'connected_by_uuid' => $customer->connected_by_user_id !== null ? User::query()->whereKey($customer->connected_by_user_id)->value('uuid') : null,
            ],
        ];
    }

    /**
     * The customer page's installation block: the open balance, every
     * installation charge with its own balance, and what the viewer may do.
     *
     * @return array{balance: string, charges: list<array<string, mixed>>, can_create: bool}
     */
    private function shapeInstallation(Customer $customer): array
    {
        $user = request()->user();
        $charges = app(CustomerChargeService::class)->forCustomer($customer);

        return [
            'balance' => CustomerChargeService::openInstallationBalance($customer),
            'charges' => $charges->map(fn (CustomerCharge $charge): array => $this->shapeCharge($charge))->values()->all(),
            'can_create' => $user->can('create', CustomerCharge::class) && ! $customer->trashed(),
            // Customers from before installation fees: "not applicable",
            // never "unpaid" (phase 2, item 9).
            'applicable' => $charges->isNotEmpty() || $customer->connected_on !== null,
            'connected_on' => $customer->connected_on?->toDateString(),
            'billing_starts_period' => $customer->billing_starts_period,
            // Registered after their connection month closed: the months that passed unbilled, and the charge decision.
            'late' => ($late = app(LateRegistrationService::class)->summary($customer)) === null ? null : [
                ...$late,
                'can_charge' => ($late['charge'] === null || $late['charge']['reversed']) && $this->context->can('arrears.approve') && ! $customer->trashed(),
                // Corrections (owner, 2026-10-28) — the server checks each again.
                'connected_on' => $customer->connected_on?->toDateString(),
                'registered_in' => BusinessTimezone::periodOf($customer->created_at),
                'can_correct' => request()->user()->can('update', $customer) && ! $customer->trashed(),
                'can_withdraw' => $late['charge'] !== null && in_array($late['charge']['status'], ['pending', 'pending_second_approval'], true)
                    && $late['charge']['requested_by_id'] === request()->user()->id,
                'can_decide' => $late['charge'] !== null && in_array($late['charge']['status'], ['pending', 'pending_second_approval'], true)
                    && request()->user()->can('approve', ArrearsAdjustment::query()->where('uuid', $late['charge']['uuid'])->first()),
                'can_reverse' => $late['charge'] !== null && $late['charge']['status'] === 'approved' && ! $late['charge']['reversed']
                    && $late['charge']['reversal_status'] === null && $this->context->can('arrears.request'),
                // What became of the money the customer says they paid someone — a manager, not whoever connected them.
                'can_settle_says_paid' => (float) ($late['says_paid_amount'] ?? 0) > 0 && $late['says_paid_settled'] === null
                    && $this->context->can('vouchers.approve') && $customer->connected_by_user_id !== request()->user()->id,
            ],
        ];
    }

    /**
     * The customer page's relocation and re-installation fees (the same
     * charge model as installation, another kind): every such charge with
     * its balance, the "Relocate" / "Re-install" permissions, the
     * company's default fees and the zones a relocation may move to.
     *
     * @return array{balance: string, charges: list<array<string, mixed>>, can_relocate: bool, can_reinstall: bool, can_record_payment: bool, default_fees: array{relocation: ?string, reinstallation: ?string}, zones: array<int, array{uuid: string, name: string, branch_name: ?string}>}
     */
    private function shapeOneOffCharges(Customer $customer): array
    {
        $user = request()->user();
        $charges = app(CustomerChargeService::class)->forCustomer($customer, CustomerCharge::SERVICE_KINDS);
        $active = ! $customer->trashed();
        $canRelocate = $active && $user->can('relocate', CustomerCharge::class);

        return [
            'balance' => array_reduce(
                CustomerChargeService::openBalancesByKind([$customer->id], CustomerCharge::SERVICE_KINDS)[$customer->id] ?? [],
                fn (string $sum, string $balance): string => bcadd($sum, $balance, 2),
                '0.00',
            ),
            'charges' => $charges->map(fn (CustomerCharge $charge): array => $this->shapeCharge($charge))->values()->all(),
            'can_relocate' => $canRelocate,
            'can_reinstall' => $active && $user->can('reinstall', CustomerCharge::class),
            'can_record_payment' => $this->context->can('payments.create'),
            'default_fees' => [
                'relocation' => CustomerRelocationService::defaultFee(CustomerCharge::KIND_RELOCATION, $customer->branch_id),
                'reinstallation' => CustomerRelocationService::defaultFee(CustomerCharge::KIND_REINSTALLATION, $customer->branch_id),
            ],
            'zones' => $canRelocate ? $this->zonesForSelect() : [],
        ];
    }

    /**
     * One charge row on the customer page — any kind.
     *
     * @return array<string, mixed>
     */
    private function shapeCharge(CustomerCharge $charge): array
    {
        $user = request()->user();

        return [
            'uuid' => $charge->uuid,
            'kind' => $charge->kind,
            'kind_label' => $charge->kindLabel(),
            // Relocation: where the customer moved from and to.
            'from_zone_name' => $charge->fromZone?->name,
            'to_zone_name' => $charge->toZone?->name,
            'from_location' => $charge->from_location,
            'to_location' => $charge->to_location,
            'note' => $charge->note,
            'amount' => (string) $charge->amount,
            'paid' => $charge->paidAmount(),
            'balance' => $charge->balance(),
            'recordable' => $charge->recordableAmount(),
            'status' => $charge->status,
            'reason' => $charge->reason,
            'requested_by_name' => $charge->requestedBy?->name,
            'created_at' => $charge->created_at?->toIso8601String(),
            'waiver_status' => $charge->waiver_status,
            'waiver_reason' => $charge->waiver_reason,
            'waiver_requested_by_name' => $charge->waiverRequestedBy?->name,
            'waiver_decided_by_name' => $charge->waiverDecidedBy?->name,
            'waiver_rejection_reason' => $charge->waiver_rejection_reason,
            'waived_amount' => $charge->waived_amount !== null ? (string) $charge->waived_amount : null,
            // Phase 2: offer, fee-override approval (D8), refund (D9).
            'offer_name' => $charge->offer?->name,
            'standard_amount' => $charge->standard_amount !== null ? (string) $charge->standard_amount : null,
            'approval_status' => $charge->approval_status,
            'approval_rejection_reason' => $charge->approval_rejection_reason,
            'refund_status' => $charge->refund_status,
            'refund_amount' => $charge->refund_amount !== null ? (string) $charge->refund_amount : null,
            'refund_reason' => $charge->refund_reason,
            'refund_requested_by_name' => $charge->refundRequestedBy?->name,
            'refund_decided_by_name' => $charge->refundDecidedBy?->name,
            'refund_rejection_reason' => $charge->refund_rejection_reason,
            'refund_voucher_number' => $charge->refundVoucher?->number,
            'refund_voucher_status' => $charge->refundVoucher?->status,
            'can' => [
                'record_payment' => $user->can('recordPayment', $charge),
                'request_waiver' => $user->can('requestWaiver', $charge),
                'decide_waiver' => $user->can('approveWaiver', $charge),
                'decide_fee' => $user->can('approveFee', $charge),
                'request_refund' => $user->can('requestRefund', $charge),
                'decide_refund' => $user->can('approveRefund', $charge),
            ],
        ];
    }

    /**
     * The customer page's free months: every request with its status, and
     * what the viewer may do (phase 2, D12).
     *
     * @return array{grants: list<array<string, mixed>>, can_request: bool, max_months: int, services: list<array{uuid: string, name: string}>}
     */
    private function shapeFreeMonths(Customer $customer): array
    {
        $user = request()->user();

        $grants = FreeMonthGrant::query()
            ->with(['service', 'requestedBy', 'decidedBy'])
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->get();

        return [
            'grants' => $grants->map(fn (FreeMonthGrant $grant): array => [
                'uuid' => $grant->uuid,
                'group_uuid' => $grant->group_uuid,
                'service' => $grant->service?->name ?? 'All services',
                'months' => (int) $grant->months,
                'reason' => $grant->reason,
                'reason_label' => FreeMonthGrant::REASON_LABELS[$grant->reason] ?? $grant->reason,
                'note' => $grant->note,
                'status' => $grant->status,
                'start_period' => $grant->start_period,
                'end_period' => $grant->endPeriod(),
                'requested_by_name' => $grant->requestedBy?->name,
                'decided_by_name' => $grant->decidedBy?->name,
                'rejection_reason' => $grant->rejection_reason,
                'cancel_reason' => $grant->cancel_reason,
                'created_at' => $grant->created_at?->toIso8601String(),
                'can' => [
                    'decide' => $user->can('approve', $grant),
                ],
            ])->values()->all(),
            ...app(FreeMonthGrantService::class)->formOptions($customer, $user),
        ];
    }

    /**
     * @return array{uuid: string, amount: string, credit: string, frequency: string, purpose: string, verification_status: string, created_at: string|null}
     */
    private function shapePayment(Payment $payment): array
    {
        return [
            'uuid' => $payment->uuid,
            'amount' => $payment->amount,
            'credit' => $payment->credit,
            'frequency' => $payment->frequency,
            'purpose' => $payment->purpose ?? Payment::PURPOSE_SUBSCRIPTION,
            'verification_status' => $payment->verification_status,
            'created_at' => $payment->created_at?->toISOString(),
        ];
    }
}
