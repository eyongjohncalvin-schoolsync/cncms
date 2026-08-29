<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\PaymentData;
use App\DataTransferObjects\VerifyPaymentData;
use App\Http\Requests\BulkVerifyPaymentRequest;
use App\Http\Requests\StoreBulkPaymentRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Requests\VerifyPaymentRequest;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\PaymentService;
use App\Services\PaymentVerificationService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web (session-auth, Inertia) counterpart of Api\PaymentController — same
 * PaymentService/PaymentVerificationService, same FormRequests/Policy, just
 * rendering Inertia pages and redirecting-with-flash instead of returning
 * JSON. See AuthController's class doc for why web controllers never call
 * the JSON /api/v1/* endpoints.
 *
 * PaymentRepository::paginate()/findByUuid() only ever eager-load the
 * `customer` relation (see PaymentRepositoryInterface's doc comment) — that
 * contract is shared with the API controller and not something this
 * controller may change. Zone and verification data the pages also need is
 * pulled in afterwards via a plain Collection::load()/Model::load() call,
 * which avoids an N+1 per row without touching the repository.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentVerificationService $verifications,
        private readonly CustomerRepositoryInterface $customers,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Payment::class);

        $perPage = (int) $request->integer('per_page', 20);

        $filters = $request->only([
            'customer_uuid', 'zone_uuid', 'verification_status', 'frequency', 'recorded_offline', 'from', 'to', 'search',
        ]);

        // Default view = current calendar month only. Payments accumulate
        // forever and this table has no archival/retention step, so with no
        // date filter this query paginated over EVERY payment this tenant
        // has ever recorded, on every single page load — the exact
        // performance/UX complaint this scoping fixes. `?scope=all` is the
        // explicit, deliberate opt-in for audit/historical lookup (see the
        // "All time" control on Payments/Index.tsx) and bypasses the month
        // default entirely, restoring the old "everything" behavior on
        // request rather than by default. A caller who already supplies
        // their OWN from/to (e.g. picking a specific past month, or a saved
        // link/bookmark) is left untouched by both checks below — this only
        // fills the gap when NEITHER an explicit range NOR scope=all was
        // given, so nothing about today's explicit-range behavior changes.
        $isAllTimeScope = $request->query('scope') === 'all';

        if (! $isAllTimeScope && ! isset($filters['from']) && ! isset($filters['to'])) {
            $filters['from'] = now()->startOfMonth()->toDateString();
            $filters['to'] = now()->endOfMonth()->toDateString();
        }

        $payments = $this->payments->list($filters, $perPage);
        $payments->getCollection()->load(['customer.zone', 'verification.verifier']);

        $raw = $payments->toArray();

        return Inertia::render('Payments/Index', [
            'payments' => [
                'data' => $payments->getCollection()
                    ->map(fn (Payment $payment): array => $this->formatPayment($payment))
                    ->values()
                    ->all(),
                'links' => $raw['links'],
                'meta' => [
                    'current_page' => $raw['current_page'],
                    'per_page' => $raw['per_page'],
                    'total' => $raw['total'],
                    'last_page' => $raw['last_page'],
                ],
            ],
            'filters' => [
                'verification_status' => $filters['verification_status'] ?? null,
                'frequency' => $filters['frequency'] ?? null,
                'search' => $filters['search'] ?? null,
                // Echoed back so the page can render an accurate "Showing:
                // <Month Year>" / "Showing: All time" label and pre-fill the
                // month picker — reflects the EFFECTIVE range actually
                // applied above (including the current-month default), not
                // just whatever the request happened to send.
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'scope' => $isAllTimeScope ? 'all' : null,
            ],
            // Tab badge counts — deliberately GLOBAL, i.e. NOT scoped to the
            // list's own from/to window (including the new current-month
            // default above). Two options were considered: (a) scope these
            // to the same month as the list, for visual consistency with
            // what's on screen, or (b) keep them global as an
            // always-accurate admin TODO signal. Went with (b): "pending"
            // in particular exists to answer "do I have any unresolved
            // payments anywhere, full stop" — scoping it to "this month"
            // would make a pending payment from last month invisible the
            // moment the calendar rolls over, silently hiding exactly the
            // kind of stale, unresolved item this badge exists to surface.
            // A viewer scoped to "this month" seeing a "Pending: 3" badge
            // that doesn't match the 1 pending row on screen is an
            // acceptable, explainable mismatch; a pending payment nobody
            // is ever nudged to look at again is not. If this table grows
            // large enough that these four COUNT queries become the
            // bottleneck, the fix is a maintained cache/aggregate, not
            // silently narrowing the signal to "this month".
            'statusCounts' => [
                'all' => Payment::query()->count(),
                'pending' => $this->payments->pendingVerificationCount(),
                'verified' => Payment::query()->where('verification_status', 'verified')->count(),
                'rejected' => Payment::query()->where('verification_status', 'rejected')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Payment::class);

        // ~549 customers total — small enough to hand the whole list to the
        // page for a client-side searchable dropdown rather than building a
        // server-side type-ahead endpoint (see PaymentController spec:
        // "don't over-engineer"). Goes through CustomerRepositoryInterface
        // (like every other customer-listing call site) rather than a raw
        // Customer::query() specifically so this picker is branch-scoped —
        // a raw query here was a real data leak: a branch-fenced caller
        // (including a flag-granted worker, see PaymentPolicy::create())
        // would otherwise see and be able to record a payment against every
        // customer in every branch, even though the POST is correctly
        // scoped via PaymentService::resolveCustomerId() ->
        // CustomerRepository::findByUuid().
        $customers = $this->customers->allMatching([]);
        $customers->load(['zone', 'latestManuscript']);

        $customers = $customers
            ->map(fn (Customer $customer): array => [
                'uuid' => $customer->uuid,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'zone_uuid' => $customer->zone?->uuid,
                'zone_name' => $customer->zone?->name,
                'bill' => (string) $customer->bill,
                'others' => (string) $customer->others,
                'level' => $customer->level,
                'status' => $customer->status,
                'location' => $customer->location,
                // Current arrears — drives the "clear arrears first" toggle on a
                // months/yearly prepayment (references/prepayment-drawdown.md Q1).
                'total_arrears' => (string) ($customer->latestManuscript?->total_arrears ?? '0'),
            ])
            ->values();

        return Inertia::render('Payments/Create', [
            'customers' => $customers,
        ]);
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $this->payments->create(PaymentData::fromArray($request->validated()));

        return redirect()->route('payments.index')->with('success', 'Payment recorded successfully.');
    }

    /**
     * Records one payment per selected customer, each at that customer's
     * own bill — see PaymentService::createBulk(). Reports a partial
     * success (some created, some skipped) rather than treating any
     * failure as fatal for the whole batch.
     */
    public function storeBulk(StoreBulkPaymentRequest $request): RedirectResponse
    {
        $result = $this->payments->createBulk(
            $request->validated('customer_uuids'),
            $request->validated('frequency'),
            $request->validated('months'),
        );

        $createdCount = count($result['created']);
        $failedCount = count($result['failed']);

        $message = $createdCount === 1 ? '1 payment recorded.' : "{$createdCount} payments recorded.";

        if ($failedCount > 0) {
            $message .= ' '.($failedCount === 1 ? '1 was skipped: ' : "{$failedCount} were skipped: ")
                .implode(' ', $result['failed']);
        }

        return redirect()->route('payments.index')
            ->with($createdCount > 0 ? 'success' : 'error', $message);
    }

    /**
     * Approves many pending payments at once — see
     * PaymentVerificationService::verifyMany(). Only exact bill matches are
     * ever eligible; anything skipped stays pending for individual review.
     */
    public function bulkVerify(BulkVerifyPaymentRequest $request): RedirectResponse
    {
        $result = $this->verifications->verifyMany($request->validated('payment_uuids'), $request->user());

        $verifiedCount = count($result['verified']);
        $skippedCount = count($result['skipped']);

        $message = $verifiedCount === 1 ? '1 payment verified.' : "{$verifiedCount} payments verified.";

        if ($skippedCount > 0) {
            $message .= ' '.($skippedCount === 1 ? '1 was skipped (no longer eligible).' : "{$skippedCount} were skipped (no longer eligible).");
        }

        return back()->with($verifiedCount > 0 ? 'success' : 'error', $message);
    }

    public function show(Payment $payment): Response
    {
        $this->authorize('view', $payment);

        $payment = $this->payments->findOrFail($payment->uuid);
        // latestManuscript added alongside zone/verification (same
        // load()-after-the-fact idiom this class's doc comment describes,
        // not a repository contract change) purely so Payments/Show.tsx can
        // offer an "Adjust Arrears" entry point without a page navigation —
        // see this feature's design doc, 2026-08-27 addendum.
        $payment->load(['customer.zone', 'customer.latestManuscript', 'verification.verifier']);

        return Inertia::render('Payments/Show', [
            'payment' => $this->formatPayment($payment),
            // Mirrors PaymentPolicy::update()'s own role check exactly (that
            // policy method takes no target Payment — it's a pure
            // class-level role gate) — same "compute the flag the page
            // needs, controller-side" idiom ComplaintController::show() uses
            // for its own can_manage prop.
            'can_manage' => $this->context->isAnyOf('super', 'admin', 'manager'),
            // Same idiom as can_manage above, but mirroring PaymentPolicy::
            // delete()'s stricter super/admin-only role check instead of
            // update()'s super/admin/manager.
            'can_delete' => $this->context->isAnyOf('super', 'admin'),
        ]);
    }

    /**
     * Correcting a previously recorded payment's amount/frequency/months/
     * credit — distinct from verify() above, which only approves/rejects a
     * pending payment and never touches these fields. Gated to super/admin/
     * manager by PaymentPolicy::update(), same roles as the class doc
     * comment's "only admin/super edit" convention.
     */
    public function edit(Payment $payment): Response
    {
        $this->authorize('update', $payment);

        $payment = $this->payments->findOrFail($payment->uuid);
        $payment->load(['customer.zone', 'verification.verifier']);

        return Inertia::render('Payments/Edit', [
            'payment' => $this->formatPayment($payment),
        ]);
    }

    public function update(UpdatePaymentRequest $request, Payment $payment): RedirectResponse
    {
        $this->payments->update($payment, PaymentData::fromArray($request->validated()));

        return redirect()->route('payments.show', $payment)->with('success', 'Payment updated successfully.');
    }

    /**
     * Permanently removes a recorded payment — gated to super/admin only by
     * PaymentPolicy::delete() (stricter than update()'s super/admin/
     * manager), per the "only admin/super edit or delete" convention that
     * policy's class doc comment documents. Like update() above,
     * PaymentPolicy::delete() is a pure class-level check (no $payment
     * parameter) — passing $payment to authorize() here just matches
     * update()'s own call above and PHP happily ignores the unused extra
     * argument. PaymentService::delete() -> PaymentRepository::delete() is
     * a hard delete (Payment has no SoftDeletes); its
     * payment_verifications row cascades on delete at the DB level (see
     * that table's migration), so no separate cleanup is needed here.
     */
    public function destroy(Payment $payment): RedirectResponse
    {
        $this->authorize('delete', $payment);

        $this->payments->delete($payment);

        return redirect()->route('payments.index')->with('success', 'Payment deleted successfully.');
    }

    public function verify(VerifyPaymentRequest $request, Payment $payment): RedirectResponse
    {
        $data = VerifyPaymentData::fromArray($request->validated());

        $this->verifications->verify($payment, $data, $request->user());

        $message = $data->action === 'approve' ? 'Payment verified.' : 'Payment rejected.';

        return back()->with('success', $message);
    }

    public function uploadReceipt(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('attachReceipt', $payment);

        $request->validate([
            'receipt' => ['required', 'image', 'max:2048'],
        ]);

        // Stored on the `public` disk (config/filesystems.php); serving it
        // via Storage::url() requires `php artisan storage:link` to have
        // been run at deploy time — mirrors Api\PaymentController::uploadReceipt().
        $storedPath = $request->file('receipt')->store('receipts/payments', 'public');

        $this->verifications->attachReceipt($payment, $storedPath);

        return back()->with('success', 'Receipt uploaded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPayment(Payment $payment): array
    {
        return [
            'uuid' => $payment->uuid,
            'customer_uuid' => $payment->customer->uuid,
            'customer_name' => $payment->customer->name,
            'customer_bill' => (string) $payment->customer->bill,
            'zone_name' => $payment->customer->zone?->name,
            // Only populated on Payments/Show.tsx (where `customer.
            // latestManuscript` is eager-loaded — see show()'s doc comment
            // above); null on Payments/Index.tsx's list rows, which never
            // load that relation. Feeds the "Adjust Arrears" entry point's
            // current-balance display the same way Customers/Show.tsx's
            // `manuscript.total_arrears` does.
            'customer_total_arrears' => $payment->customer->relationLoaded('latestManuscript')
                ? $payment->customer->latestManuscript?->total_arrears
                : null,
            'amount' => (string) $payment->amount,
            'credit' => (string) $payment->credit,
            'frequency' => $payment->frequency,
            'months' => $payment->months,
            'expiration_date' => $payment->expiration_date?->toDateString(),
            'verification_status' => $payment->verification_status,
            'recorded_offline' => $payment->recorded_offline,
            'recorded_by_device' => $payment->recorded_by_device,
            'created_at' => $payment->created_at?->toIso8601String(),
            'collected_at' => $payment->collected_at?->toIso8601String(),
            'processed_at' => $payment->processed_at?->toIso8601String(),
            'verification' => $payment->verification ? $this->formatVerification($payment->verification) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatVerification(PaymentVerification $verification): array
    {
        return [
            'uuid' => $verification->uuid,
            'status' => $verification->status,
            'receipt_photo_url' => $verification->receipt_photo_path
                ? Storage::disk('public')->url($verification->receipt_photo_path)
                : null,
            'momo_ref' => $verification->momo_ref,
            'momo_status' => $verification->momo_status,
            'verified_by' => $verification->verifier?->name,
            'verified_at' => $verification->verified_at?->toIso8601String(),
            'notes' => $verification->notes,
        ];
    }
}
