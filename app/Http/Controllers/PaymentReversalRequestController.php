<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentReversalRequest;
use App\Services\PaymentReversalRequestService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Collectors' requests for the office to reverse a payment (owner,
 * 2026-10-28) — see App\Services\PaymentReversalRequestService. The office
 * opens the payment and reverses it there (the Reverse dialog, with the
 * collector's reason), or declines here.
 */
class PaymentReversalRequestController extends Controller
{
    public function __construct(
        private readonly PaymentReversalRequestService $requests,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Payment::class);

        $requests = PaymentReversalRequest::query()
            ->with(['payment.customer', 'requestedBy:id,name'])
            ->where('status', PaymentReversalRequest::STATUS_PENDING)
            ->whereHas('payment', fn ($query) => $query->forBranch(TenantContext::currentBranchId()))
            ->oldest()
            ->get();

        return Inertia::render('Payments/ReversalRequests', [
            'requests' => $requests->map(fn (PaymentReversalRequest $request): array => [
                'uuid' => $request->uuid,
                'reason' => $request->reason,
                'requested_by' => $request->requestedBy?->name,
                'requested_at' => $request->created_at?->toIso8601String(),
                'payment' => [
                    'uuid' => $request->payment->uuid,
                    'customer_name' => $request->payment->customer?->name,
                    'amount' => (string) $request->payment->amount,
                    'method' => $request->payment->method ?? 'cash',
                    'verification_status' => $request->payment->verification_status,
                    'collected_at' => ($request->payment->collected_at ?? $request->payment->created_at)?->toIso8601String(),
                ],
                'can_decline' => request()->user()->can('declineReversal', $request->payment),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('requestReversal', $payment);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->requests->request($payment, $request->user(), $validated['reason']);

        return back()->with('success', 'Sent to the office — they reverse it or tell you why not.');
    }

    public function decline(Request $request, PaymentReversalRequest $reversalRequest): RedirectResponse
    {
        $this->authorize('declineReversal', $reversalRequest->payment);

        $validated = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        $this->requests->decline($reversalRequest, $request->user(), $validated['note']);

        return back()->with('success', 'Declined — the collector is told why.');
    }
}
