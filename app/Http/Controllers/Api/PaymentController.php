<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\PaymentData;
use App\DataTransferObjects\VerifyPaymentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Requests\VerifyPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PrepaymentOffer;
use App\Services\PaymentService;
use App\Services\PaymentVerificationService;
use App\Support\BranchPrices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentVerificationService $verifications,
    ) {}

    /**
     * Before the phone saves a payment, when it has signal (owner,
     * 2026-10-28): has anyone — the office, another collector, this agent on
     * another phone — already recorded one for this customer that a new
     * payment of `amount` may repeat? The same check saving runs
     * (PaymentService::earlierPaymentFor()), so the agent is asked in the
     * field instead of the office finding it later.
     */
    public function check(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $validated = $request->validate(['amount' => ['required', 'numeric', 'gt:0']]);
        $earlier = $this->payments->earlierPaymentFor($customer, (string) $validated['amount']);

        return response()->json(['data' => [
            'earlier' => $earlier === null ? null : [
                'uuid' => $earlier->uuid,
                'amount' => (string) $earlier->amount,
                'collected_at' => ($earlier->collected_at ?? $earlier->created_at)->toIso8601String(),
                'recorded_by' => $earlier->recordedBy?->name,
                'recorded_by_me' => $earlier->recorded_by === $request->user()->id,
                'receipt_number' => $earlier->receipt?->receipt_number,
                'verification_status' => $earlier->verification_status,
            ],
        ]]);
    }

    /**
     * This month's payments for customers in the agent's zones that someone
     * else recorded — a walk-in at the office, another collector (owner,
     * 2026-10-28). They stay under the zone and show on the agent's phone,
     * named, so the agent never collects again and knows the money is not
     * theirs to hand over.
     */
    public function byOthers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        return response()->json(['data' => $this->payments->byOthersInMyZones($request->user())->map(fn (Payment $payment): array => [
            'uuid' => $payment->uuid,
            'customer_uuid' => $payment->customer?->uuid,
            'customer_name' => $payment->customer?->name,
            'amount' => (string) $payment->amount,
            'method' => $payment->method ?? 'cash',
            'collected_at' => ($payment->collected_at ?? $payment->created_at)?->toIso8601String(),
            'recorded_by' => $payment->recordedBy?->name,
            'where' => $payment->recorded_offline ? 'field' : 'office',
            'receipt_number' => $payment->receipt?->receipt_number,
            'verification_status' => $payment->verification_status,
        ])->values()->all()]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $perPage = (int) $request->integer('per_page', 25);

        $filters = $request->only([
            'customer_uuid', 'zone_uuid', 'verification_status', 'frequency', 'recorded_offline', 'from', 'to',
        ]);

        $payments = $this->payments->list($filters, $perPage);

        return PaymentResource::collection($payments)->response();
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        $payment = $this->payments->findOrFail($payment->uuid);

        return (new PaymentResource($payment))->response();
    }

    /**
     * Prepayment offers on sale today, at the price of the caller's branch
     * (the agent's, or headquarters'), plus every branch's price
     * (`branch_prices`, by branch uuid) — the phone sells at the customer's
     * branch price, as the server charges (PaymentService::applyPrepaymentOffer()).
     * The phone caches them so an agent can sell one offline; the server
     * re-checks each payment when it syncs.
     */
    public function prepaymentOffers(): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $prices = BranchPrices::current();
        $branches = Branch::query()->get(['id', 'uuid']);

        return response()->json(['data' => PrepaymentOffer::query()->available()->orderBy('months')->get()
            ->map(fn (PrepaymentOffer $offer): array => [
                'uuid' => $offer->uuid,
                'name' => $offer->name,
                'months' => $offer->months,
                'price' => $prices->prepaymentOfferPrice($offer),
                'branch_prices' => $branches->mapWithKeys(fn (Branch $branch): array => [
                    $branch->uuid => BranchPrices::for($branch->id)->prepaymentOfferPrice($offer),
                ])->all(),
            ])->values()->all()]);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $payment = $this->payments->create(PaymentData::fromArray($request->validated()));

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    public function update(UpdatePaymentRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->payments->update($payment, PaymentData::fromArray($request->validated()));

        return (new PaymentResource($payment))->response();
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $this->authorize('delete', $payment);

        $this->payments->delete($payment);

        return response()->json(['message' => 'Payment deleted']);
    }

    public function verify(VerifyPaymentRequest $request, Payment $payment): JsonResponse
    {
        $data = VerifyPaymentData::fromArray($request->validated());

        $payment = $this->verifications->verify($payment, $data, $request->user());

        $message = $data->action === 'approve' ? 'Payment verified' : 'Payment rejected';

        return (new PaymentResource($payment))->additional(['message' => $message])->response();
    }

    public function uploadReceipt(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('attachReceipt', $payment);

        $request->validate([
            'receipt' => ['required', 'image', 'max:2048'],
        ]);

        // Stored on the `public` disk (config/filesystems.php); serving it
        // via Storage::url() requires `php artisan storage:link` to have
        // been run at deploy time — not something this request should do.
        $storedPath = $request->file('receipt')->store('receipts/payments', 'public');

        $verification = $this->verifications->attachReceipt($payment, $storedPath);

        return response()->json([
            'message' => 'Receipt uploaded',
            'receipt_url' => Storage::disk('public')->url($verification->receipt_photo_path),
        ]);
    }
}
