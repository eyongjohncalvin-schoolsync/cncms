<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\PaymentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
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
}
