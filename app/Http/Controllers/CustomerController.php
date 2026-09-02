<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\CustomerData;
use App\Exports\CustomerImportTemplateExport;
use App\Http\Requests\ArchiveCustomerRequest;
use App\Http\Requests\BulkUpdateCustomerBillRequest;
use App\Http\Requests\DisconnectCustomerRequest;
use App\Http\Requests\ImportCustomersRequest;
use App\Http\Requests\ReconnectCustomerRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\SuspendCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\ArrearsAdjustment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Zone;
use App\Services\ArrearsAdjustmentService;
use App\Services\CustomerImportService;
use App\Services\CustomerService;
use App\Services\CustomerStatusService;
use App\Services\ManuscriptService;
use App\Services\ZoneService;
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

        $filters = $request->only(['zone_uuid', 'status', 'level', 'search']);

        $paginator = $this->customers->list([...$filters, 'archived' => $archived], 15);

        return Inertia::render('Customers/Index', [
            'archived_view' => $archived,
            'customers' => [
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
            ],
            'zones' => $this->zonesForSelect(),
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
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->customers->create(CustomerData::fromArray($request->validated()));

        return redirect()->route('customers.index')->with('success', 'Customer created.');
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
        $result = $this->customerImports->import($request->file('file'), $request->user());

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

        return Inertia::render('Customers/Edit', [
            'customer' => $this->shapeCustomer($customer->loadMissing('zone')),
            'zones' => $this->zonesForSelect(),
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
            $request->validated('arrears_payment'),
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

        $payment = $customer->payments()->latest('created_at')->first();

        return response()->json([
            'payment' => $payment ? $this->shapePayment($payment) : null,
        ]);
    }

    /**
     * @return array<int, array{uuid: string, name: string, town: string}>
     */
    private function zonesForSelect(): array
    {
        return $this->zones->all()
            ->map(fn (Zone $zone) => [
                'uuid' => $zone->uuid,
                'name' => $zone->name,
                'town' => $zone->town,
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
            'zone_uuid' => $customer->zone?->uuid,
            'zone_name' => $customer->zone?->name,
            'bill' => $customer->bill,
            'others' => $customer->others,
            'level' => $customer->level,
            'status' => $customer->status,
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
        ];
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
                'period' => $customer->latestManuscript->period,
            ] : null,
            'recent_payments' => $customer->payments->map(fn (Payment $payment) => $this->shapePayment($payment))->all(),
            'arrears_adjustments' => $this->shapeArrearsAdjustments($customer),
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
     * @return array{uuid: string, amount: string, credit: string, frequency: string, verification_status: string, created_at: string|null}
     */
    private function shapePayment(Payment $payment): array
    {
        return [
            'uuid' => $payment->uuid,
            'amount' => $payment->amount,
            'credit' => $payment->credit,
            'frequency' => $payment->frequency,
            'verification_status' => $payment->verification_status,
            'created_at' => $payment->created_at?->toISOString(),
        ];
    }
}
