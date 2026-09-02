<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\VerifyPaymentData;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\PaymentVerificationRepositoryInterface;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the payment verification/approval workflow described in
 * business-rules.md section 5 ("Verification flow (v2)"). Approve/reject
 * always writes to both `payment_verifications` (the review record) and
 * `payments.verification_status` (the gate manuscript:calculate checks) in
 * one transaction, so the two never drift out of sync.
 */
class PaymentVerificationService
{
    public function __construct(
        private readonly PaymentVerificationRepositoryInterface $verifications,
        private readonly PaymentRepositoryInterface $payments,
        private readonly TenantContext $context,
        private readonly PaymentReceiptService $receipts,
    ) {}

    public function verify(Payment $payment, VerifyPaymentData $data, User $actor): Payment
    {
        DB::transaction(function () use ($payment, $data, $actor): void {
            $verification = $this->verifications->firstOrCreateForPayment($payment->id);

            if ($data->action === 'approve') {
                $this->verifications->update($verification, [
                    'status' => 'approved',
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                    'momo_ref' => $data->momoRef,
                    'momo_status' => $data->momoRef ? 'confirmed' : 'not_checked',
                    'notes' => $data->notes,
                ]);

                $this->payments->update($payment, ['verification_status' => 'verified']);

                // Auto-issue the customer's payment receipt (explicit
                // call-site wiring, not an observer — see
                // App\Services\NotificationService's class doc). Idempotent:
                // a re-approval reuses the existing row. In the same
                // transaction as the status write, so a verified payment
                // always has a receipt.
                $this->receipts->issueFor($payment, $actor);
            } else {
                $this->verifications->update($verification, [
                    'status' => 'rejected',
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                    'notes' => $data->notes,
                ]);

                $this->payments->update($payment, ['verification_status' => 'rejected']);

                // A payment that was verified (and so had a receipt issued)
                // and is now being rejected: void the receipt, keep the row
                // for audit. No-op when there is no receipt.
                $this->receipts->voidForPayment($payment);
            }
        });

        $this->forgetShowCache($payment->uuid);

        // Extends the same forget-on-write to /reports: an approve/reject
        // flip changes the Daily tier's "verifications actioned today" and
        // "pending queue" figures, and the Weekly tier's verification-SLA
        // block, immediately — see ReportService::forgetCache()'s doc
        // comment for the "own key + 'all' key" tradeoff this follows.
        // verified_at is effectively "now", so today's WAT calendar day is
        // always the right period to invalidate.
        ReportService::forgetCache(Carbon::now(BusinessTimezone::WAT), TenantContext::currentBranchId());

        return $payment->fresh(['customer', 'verification.verifier']);
    }

    /**
     * Approves many pending payments in one pass — the "10 customers each
     * paid exactly their monthly bill, don't make me click Approve 10
     * times" workflow. Each payment still goes through verify() and its
     * own transaction individually (rather than one large transaction
     * wrapping the whole batch), so one failure can't roll back approvals
     * that already succeeded, and no single transaction holds locks across
     * the whole batch.
     *
     * Only a payment that is (a) still pending and (b) paid at *exactly*
     * the customer's current bill is approved — this is re-checked here
     * even though the frontend only ever offers exact matches for
     * selection, because an approval action can't rely solely on
     * client-supplied selection as its safety gate. Anything else is
     * skipped, not rejected: a skipped payment is untouched and still
     * available for the normal single-payment review.
     *
     * (c) A third, per-item check for an `agent`-role actor specifically:
     * PaymentPolicy::bulkVerify() only gates whether this actor may call
     * bulk-verify AT ALL — it has no target Payment to zone-check against
     * (unlike verify()/PaymentPolicy::verify(), which zone-fences an agent
     * to their own zone via TenantContext::zoneId). Without this loop-level
     * re-check, an agent could bypass that zone fence entirely by simply
     * submitting UUIDs of payments outside their own zone to this bulk
     * endpoint. A payment outside the actor's zone is skipped (added to
     * $skipped), never silently dropped, exactly like the pending/exact-
     * match checks above.
     *
     * @param  string[]  $paymentUuids
     * @return array{verified: string[], skipped: array<string, string>}
     */
    public function verifyMany(array $paymentUuids, User $actor): array
    {
        $verified = [];
        $skipped = [];
        $isAgent = $this->context->role === 'agent';
        $zoneId = $this->context->zoneId;

        foreach ($paymentUuids as $uuid) {
            $payment = $this->payments->findByUuid($uuid, ['customer']);

            if (! $payment) {
                $skipped[$uuid] = 'Payment not found.';

                continue;
            }

            if ($payment->verification_status !== 'pending') {
                $skipped[$uuid] = 'No longer pending.';

                continue;
            }

            if (bccomp((string) $payment->amount, (string) $payment->customer->bill, 2) !== 0) {
                $skipped[$uuid] = "Amount does not exactly match {$payment->customer->name}'s bill.";

                continue;
            }

            if ($isAgent && ($zoneId === null || $payment->customer->zone_id !== $zoneId)) {
                $skipped[$uuid] = 'Outside your zone.';

                continue;
            }

            $this->verify($payment, new VerifyPaymentData(
                action: 'approve',
                notes: "Bulk-verified — amount matches {$payment->customer->name}'s standard monthly bill.",
            ), $actor);

            $verified[] = $uuid;
        }

        return ['verified' => $verified, 'skipped' => $skipped];
    }

    /**
     * Guarded against replacing evidence on an already-approved payment: once
     * a payment is `verified`, its receipt is the evidence an admin/manager
     * relied on to approve it, and silently swapping it afterwards would be
     * an invisible tamper vector on the approved chain of custody
     * (audit-strategy.md section 6). Rejected/pending payments remain
     * re-uploadable — business-rules.md section 2 explicitly allows a
     * rejected payment to be "re-submitted by an agent with new evidence",
     * which requires attaching a new receipt before the next verify() call.
     * To correct evidence on a verified payment, reject then re-approve
     * through verify() so the change goes through the audited state machine
     * instead of an unaudited in-place file swap.
     */
    public function attachReceipt(Payment $payment, string $storedPath): PaymentVerification
    {
        if ($payment->verification_status === 'verified') {
            throw ValidationException::withMessages([
                'receipt' => ['This payment is already verified; its receipt evidence cannot be replaced. Reject the payment to attach new evidence.'],
            ]);
        }

        $verification = $this->verifications->firstOrCreateForPayment($payment->id);

        $verification = $this->verifications->update($verification, ['receipt_photo_path' => $storedPath]);

        $this->forgetShowCache($payment->uuid);

        return $verification;
    }

    /**
     * Must match App\Services\PaymentService::findOrFail()'s exact key
     * format (including the :branchId suffix) — a bare "payments:show:{uuid}"
     * forget() here was a silent no-op against the real, branch-suffixed
     * key (the identical bug class fixed 2026-08-27 in
     * CustomerStatusService/CustomerManuscriptRecalculationService — see
     * their doc comments), so an approve/reject/attachReceipt could leave
     * the payment detail page's cached figures stale for up to its 30s
     * TTL. Same "own branch key + 'all' key" tradeoff as
     * PaymentService::forgetShowCache(): a verify() call from a branch
     * context different than whichever branch context last cached the
     * page still only reliably covers those two variants, not every
     * possible branch-scoped entry.
     */
    private function forgetShowCache(string $uuid): void
    {
        Cache::forget("payments:show:{$uuid}:".(TenantContext::currentBranchId() ?? 'all'));
        Cache::forget("payments:show:{$uuid}:all");
    }
}
