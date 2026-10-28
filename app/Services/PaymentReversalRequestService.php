<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentReversalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A collector asks the office to reverse one of their payments (owner,
 * 2026-10-28) — the collector never undoes money themselves. The office
 * decides in the Reverse dialog (PaymentVerificationService::reverse()
 * closes the request) or declines it with a note. One open request per
 * payment; the requester is told either way.
 */
class PaymentReversalRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly CashNotificationService $cashNotifier,
    ) {}

    public function request(Payment $payment, User $requester, string $reason): PaymentReversalRequest
    {
        if ($payment->recorded_by !== $requester->id) {
            throw ValidationException::withMessages(['reason' => 'Only the person who recorded this payment can ask for it to be reversed.']);
        }

        if ($payment->isReversed() || $payment->verification_status === 'rejected') {
            throw ValidationException::withMessages(['reason' => 'This payment is already rejected.']);
        }

        $request = DB::transaction(function () use ($payment, $requester, $reason): PaymentReversalRequest {
            Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if (PaymentReversalRequest::query()->where('payment_id', $payment->id)->where('status', PaymentReversalRequest::STATUS_PENDING)->exists()) {
                throw ValidationException::withMessages(['reason' => 'The office already has a request to reverse this payment.']);
            }

            return PaymentReversalRequest::query()->create([
                'payment_id' => $payment->id,
                'requested_by' => $requester->id,
                'reason' => mb_substr($reason, 0, 500),
                'status' => PaymentReversalRequest::STATUS_PENDING,
            ]);
        });

        $payment->loadMissing('customer');
        $amount = number_format((float) $payment->amount, 0, '.', ' ');

        $this->cashNotifier->toHoldersOf(
            'payments.verify',
            'payment.reversal_requested',
            'warning',
            'Reversal requested',
            "{$requester->name} asks to reverse {$payment->customer?->name}'s payment of {$amount} FCFA: {$request->reason}",
            "/payments/{$payment->uuid}",
            'payment',
            $payment->uuid,
        );

        return $request;
    }

    /** The office said no — with a note the collector sees. Never by the requester. */
    public function decline(PaymentReversalRequest $request, User $actor, string $note): PaymentReversalRequest
    {
        if ($request->requested_by === $actor->id) {
            throw ValidationException::withMessages(['note' => 'This is your own request — someone else in the office decides it.']);
        }

        if (! $request->isPending()) {
            throw ValidationException::withMessages(['note' => 'This request was already decided.']);
        }

        $request->update([
            'status' => PaymentReversalRequest::STATUS_DECLINED,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => mb_substr($note, 0, 500),
        ]);

        $this->tellRequester($request, 'Reversal declined', "The office kept the payment: {$note}");

        return $request;
    }

    /** The payment was reversed (or rejected): its open request is done. */
    public function markDone(Payment $payment, User $actor): void
    {
        $request = PaymentReversalRequest::query()->where('payment_id', $payment->id)->where('status', PaymentReversalRequest::STATUS_PENDING)->first();

        if ($request === null) {
            return;
        }

        $request->update(['status' => PaymentReversalRequest::STATUS_DONE, 'decided_by' => $actor->id, 'decided_at' => now()]);

        $this->tellRequester($request, 'Payment reversed', 'The office reversed the payment you asked about.');
    }

    private function tellRequester(PaymentReversalRequest $request, string $title, string $body): void
    {
        $request->loadMissing(['requestedBy', 'payment.customer']);

        if ($request->requestedBy === null || $request->payment === null) {
            return;
        }

        $this->notifications->broadcastToUser(
            $request->requestedBy,
            'payment.reversal_decided',
            'info',
            $title,
            "{$request->payment->customer?->name}: {$body}",
            "/payments/{$request->payment->uuid}",
            'payment',
            $request->payment->uuid,
        );
    }
}
