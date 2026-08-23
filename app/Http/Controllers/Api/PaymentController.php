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
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\PaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentVerificationService $verifications,
    ) {}

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
