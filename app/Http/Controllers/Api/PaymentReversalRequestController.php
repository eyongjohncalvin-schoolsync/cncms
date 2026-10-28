<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentReversalRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The phone's "Ask the office to reverse" on a synced payment (owner,
 * 2026-10-28) — App\Services\PaymentReversalRequestService.
 */
class PaymentReversalRequestController extends Controller
{
    public function __construct(
        private readonly PaymentReversalRequestService $requests,
    ) {}

    public function store(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('requestReversal', $payment);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $reversalRequest = $this->requests->request($payment, $request->user(), $validated['reason']);

        return response()->json(['data' => ['uuid' => $reversalRequest->uuid, 'status' => $reversalRequest->status]], 201);
    }
}
