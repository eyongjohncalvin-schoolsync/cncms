<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\PaymentData;
use App\DataTransferObjects\VerifyPaymentData;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\VerifyPaymentRequest;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Services\PaymentService;
use App\Services\PaymentVerificationService;
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
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Payment::class);

        $perPage = (int) $request->integer('per_page', 20);

        $filters = $request->only([
            'customer_uuid', 'zone_uuid', 'verification_status', 'frequency', 'recorded_offline', 'from', 'to',
        ]);

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
            ],
            // Tab badge counts — deliberately unfiltered by the current
            // query (each tab always shows the total in that status,
            // regardless of which tab is currently selected).
            'statusCounts' => [
                'all' => Payment::query()->count(),
                'pending' => Payment::query()->where('verification_status', 'pending')->count(),
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
        // "don't over-engineer").
        $customers = Customer::query()
            ->with('zone')
            ->orderBy('name')
            ->get()
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

    public function show(Payment $payment): Response
    {
        $this->authorize('view', $payment);

        $payment = $this->payments->findOrFail($payment->uuid);
        $payment->load(['customer.zone', 'verification.verifier']);

        return Inertia::render('Payments/Show', [
            'payment' => $this->formatPayment($payment),
        ]);
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
            'zone_name' => $payment->customer->zone?->name,
            'amount' => (string) $payment->amount,
            'credit' => (string) $payment->credit,
            'frequency' => $payment->frequency,
            'months' => $payment->months,
            'expiration_date' => $payment->expiration_date?->toDateString(),
            'verification_status' => $payment->verification_status,
            'recorded_offline' => $payment->recorded_offline,
            'recorded_by_device' => $payment->recorded_by_device,
            'created_at' => $payment->created_at?->toIso8601String(),
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
