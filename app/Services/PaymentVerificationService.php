<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ArrearsAdjustmentData;
use App\DataTransferObjects\PaymentData;
use App\DataTransferObjects\VerifyPaymentData;
use App\Jobs\RecalculateCustomerManuscriptsForwardJob;
use App\Models\CommandRun;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\RemittanceLine;
use App\Models\User;
use App\Models\Voucher;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\PaymentVerificationRepositoryInterface;
use App\Support\BranchLookup;
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
    /** The bon category for cash handed in for a payment recorded by mistake, going back to its collector. */
    public const string RETURN_TO_COLLECTOR_CATEGORY = 'Returned to collector';

    public function __construct(
        private readonly PaymentVerificationRepositoryInterface $verifications,
        private readonly PaymentRepositoryInterface $payments,
        private readonly TenantContext $context,
        private readonly PaymentReceiptService $receipts,
    ) {}

    public function verify(Payment $payment, VerifyPaymentData $data, User $actor): Payment
    {
        DB::transaction(function () use ($payment, $data, $actor): void {
            // Re-read under a lock: two decisions on the same payment at once
            // must not both pass the checks below on a stale copy.
            $current = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail(['id', 'verification_status', 'reversed_at']);

            if ($current->isReversed()) {
                throw ValidationException::withMessages(['action' => 'This payment was reversed — record the correct payment again instead.']);
            }

            // Undoing a verified payment goes through reverse(), which checks
            // the remittance and the bill run — never a plain reject.
            if ($data->action === 'reject' && $current->verification_status === 'verified') {
                throw ValidationException::withMessages(['action' => 'This payment is already verified — use Reverse payment instead.']);
            }

            // Its collector owes a handover for it: rejecting it must say what
            // happened to the money, so reverse() handles it (owner, 2026-10-28).
            if ($data->action === 'reject' && $current->verification_status === 'pending' && $payment->isUnderCashControls()) {
                throw ValidationException::withMessages(['action' => 'Say what happened to the money — use Reject on the payment, which asks.']);
            }

            $this->applyDecision($payment, $data, $actor);
        });

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
     * Undoes a verified payment that was wrong (owner's choice: reverse,
     * then record it again correctly — a verified payment's money is never
     * edited, so a receipt can't disagree with its payment):
     *  - the payment becomes rejected and its receipt is voided (kept, marked
     *    VOID) — the same path as rejecting it;
     *  - the reversal is final (`reversed_at`): it is never approved or
     *    edited again;
     *  - if a bill run already counted it and that month is still open, the
     *    customer's bills are recalculated now — a recalculation only counts
     *    verified payments, so the money simply drops out;
     *  - if that month has closed, its bill can't change, so an arrears
     *    adjustment adding the amount to the current month goes for approval;
     *  - it is refused while a bill run waits for review (its draft already
     *    counted the money).
     *
     * A pending payment whose collector owes a handover for it is rejected
     * the same way (owner, 2026-10-28) — it was never on a bill, so only the
     * money question applies.
     *
     * What happens to the money ($money, owner 2026-10-26 / 2026-10-28) —
     * nothing leaves the books without a trace:
     *  - `none` — recorded by mistake, no money was taken. Not handed in
     *    yet: it leaves the collector's handover. Already handed in: the
     *    cash in the till is the collector's own, so a bon gives it back to
     *    them;
     *  - `correct` — $correction is recorded in its place, in the same
     *    transaction and in the collector's name. Its money is the old
     *    payment's: handed in, the correction takes the old payment's place
     *    in that handover; not yet, the old payment stays on the handover
     *    for it (Payment::scopeAwaitingHandover()). Same method, not more
     *    than was taken; any difference goes back through a refund bon;
     *  - `refund` — the customer gets it all back through a refund bon; the
     *    collector still hands it in if they haven't.
     *
     * @return array{payment: Payment, adjustment_filed: bool, replacement: ?Payment, refund_voucher: ?Voucher, was_pending: bool, to_collector: bool, off_handover: bool}
     */
    public function reverse(Payment $payment, string $reason, User $actor, string $money = 'none', ?PaymentData $correction = null): array
    {
        $result = DB::transaction(function () use ($payment, $reason, $actor, $money, $correction): array {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $wasVerified = $locked->verification_status === 'verified';

            if ($locked->isReversed() || ! ($wasVerified || ($locked->verification_status === 'pending' && $locked->isUnderCashControls()))) {
                throw ValidationException::withMessages(['reason' => 'Only a verified payment can be reversed — a pending one is simply rejected.']);
            }

            $line = $locked->handoverLine();
            $this->assertMoneyChoice($locked, $line, $money, $correction);

            $runLock = app(ManuscriptRunLockService::class);
            $currentPeriod = $runLock->currentPeriod();
            $draftRun = $wasVerified ? CommandRun::query()
                ->where('command', 'manuscript:calculate')
                ->whereIn('status', ['queued', 'pending_review'])
                ->where('period', '>=', $currentPeriod)
                ->value('period') : null;

            if ($draftRun !== null) {
                throw ValidationException::withMessages(['reason' => "The {$draftRun} bill run is waiting for review and already counted this payment. Publish or discard it first, then reverse."]);
            }

            $locked->load(['customer.zone.branch', 'verification', 'receipt', 'customerCharge']);
            $this->applyDecision($locked, new VerifyPaymentData(action: 'reject', notes: ($wasVerified ? 'Reversed: ' : 'Rejected: ').$reason), $actor);
            $locked->update([
                'reversed_at' => Carbon::now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
                // Off the collector's handover only when nothing was taken and nothing handed in.
                'money_taken' => ! ($money === 'none' && $line === null),
            ]);

            $adjustmentFiled = false;
            $countedPeriod = $locked->processed_period;

            if ($countedPeriod !== null && $locked->isSubscription()) {
                if ($runLock->isPeriodLocked($countedPeriod)) {
                    app(ArrearsAdjustmentService::class)->create(new ArrearsAdjustmentData(
                        customerUuid: $locked->customer->uuid,
                        targetPeriod: $currentPeriod,
                        direction: 'increase',
                        amount: bcadd((string) $locked->amount, '0', 2),
                        reasonCategory: 'billing_error',
                        reasonNote: "Payment of {$locked->amount} FCFA counted in the closed {$countedPeriod} bill was reversed: {$reason}",
                    ), $actor->id);
                    $adjustmentFiled = true;
                } else {
                    $auditContext = ['reversed_payment_id' => $locked->id, 'triggered_by_user_id' => $actor->id];
                    app(CustomerManuscriptRecalculationService::class)->recalculateOne($locked->customer, $currentPeriod, trigger: 'payment_reversed', auditContext: $auditContext);
                    RecalculateCustomerManuscriptsForwardJob::dispatch($locked->customer_id, $countedPeriod, null, $actor->id)->afterCommit();
                }
            }

            $replacement = null;
            $taken = (string) ($line?->amount ?? $locked->amount);
            $refundAmount = $money === 'refund' ? $taken : '0.00';

            if ($money === 'correct') {
                $replacement = app(PaymentService::class)->createCorrection($correction);
                $locked->update(['replaced_by_payment_id' => $replacement->id]);

                // The money taken now stands for the correct payment, in the
                // collector's name — never the corrector's; what it didn't
                // need goes back to the customer.
                $replacement->update(['recorded_by' => $locked->recorded_by]);

                if ($line !== null && $line->payment_id === $locked->id) {
                    // Handed in: its place in the handover goes to the correction.
                    $line->update(['payment_id' => $replacement->id]);
                    $locked->update(['money_taken' => false]);
                    $refundAmount = bcsub($taken, (string) $replacement->amount, 2);
                } elseif ($line === null && $locked->paid_with_payment_id === null && bccomp((string) $replacement->amount, $taken, 2) >= 0) {
                    // Not handed in, and as much or more (a typo): the
                    // correction is what the collector owes now.
                    $locked->update(['money_taken' => false]);
                } else {
                    // Less: the payment the money was taken on stays on the
                    // handover for it, and the difference goes back.
                    $replacement->update(['paid_with_payment_id' => $locked->paid_with_payment_id ?? $locked->id]);
                    $refundAmount = bcsub($taken, (string) $replacement->amount, 2);
                }
            }

            $voucher = match (true) {
                bccomp($refundAmount, '0', 2) > 0 => $this->refundBon($locked, $refundAmount, $reason, $actor),
                $money === 'none' && $line !== null => $this->returnToCollectorBon($locked, $taken, $reason, $actor),
                default => null,
            };

            if ($voucher !== null) {
                $locked->update(['refund_voucher_id' => $voucher->id]);
            }

            return [
                'payment' => $locked,
                'adjustment_filed' => $adjustmentFiled,
                'replacement' => $replacement,
                'refund_voucher' => $voucher,
                'was_pending' => ! $wasVerified,
                'to_collector' => $money === 'none' && $voucher !== null,
                'off_handover' => $money === 'none' && $line === null,
            ];
        });

        // A collector's request to reverse it is answered.
        app(PaymentReversalRequestService::class)->markDone($result['payment'], $actor);

        // After the commit, so a page load can't re-cache the old figures in between.
        ReportService::forgetCache(Carbon::now(BusinessTimezone::WAT), TenantContext::currentBranchId());
        $this->forgetShowCache($result['payment']->uuid);

        return [...$result, 'payment' => $result['payment']->fresh(['customer', 'verification.verifier', 'receipt'])];
    }

    /**
     * A correction uses the money's own method and no more than was taken,
     * and is a monthly bill payment (an installation or field-registration
     * payment is undone through its own charge / registration).
     */
    private function assertMoneyChoice(Payment $payment, ?RemittanceLine $line, string $money, ?PaymentData $correction): void
    {
        if (! in_array($money, ['none', 'correct', 'refund'], true)) {
            throw ValidationException::withMessages(['money' => 'Say what happens to the money.']);
        }

        // Its money still sits, to be handed in, on the payment it corrected.
        if ($money === 'none' && $line === null && $payment->paid_with_payment_id !== null) {
            throw ValidationException::withMessages(['money' => 'This correction\'s money is still to be handed in on the payment it corrected — once it is handed in, it can be undone as recorded by mistake.']);
        }

        if ($money !== 'correct') {
            return;
        }

        if ($correction === null) {
            throw ValidationException::withMessages(['money' => 'Enter the correct payment.']);
        }

        if (! $payment->isSubscription() || $payment->field_installation_id !== null) {
            throw ValidationException::withMessages(['money' => 'Only a monthly bill payment is corrected this way.']);
        }

        $method = $line?->method ?? $payment->method ?? 'cash';
        $taken = (string) ($line?->amount ?? $payment->amount);

        if (($correction->method ?? 'cash') !== $method) {
            throw ValidationException::withMessages(['method' => 'The money came in as '.(Payment::METHOD_LABELS[$method] ?? $method).' — the correct payment must be too.']);
        }

        // Cash counted at a handover, or money already split with a refund:
        // the correction can't be more. Not handed in yet, it may (a typo).
        if (($line !== null || $payment->paid_with_payment_id !== null) && bccomp((string) $correction->amount, $taken, 2) > 0) {
            throw ValidationException::withMessages(['amount' => 'Only '.number_format((float) $taken, 0, '.', ',').' FCFA was taken for this payment. Record any extra as a payment of its own.']);
        }
    }

    /**
     * Cash handed in for a payment recorded by mistake is the collector's own
     * money — a bon gives it back to them from the till (owner, 2026-10-28).
     */
    private function returnToCollectorBon(Payment $payment, string $amount, string $reason, User $actor): Voucher
    {
        $collector = $payment->recordedBy;
        $category = ExpenseCategory::query()->firstOrCreate(['name' => self::RETURN_TO_COLLECTOR_CATEGORY], ['icon' => 'ti-arrow-back-up', 'is_active' => true]);

        return app(VoucherService::class)->request([
            'kind' => Voucher::KIND_EXPENSE,
            'payee_name' => mb_substr((string) ($collector?->name ?? 'Collector'), 0, 150),
            'purpose' => mb_substr("Cash returned to the collector — {$payment->customer->name}'s payment recorded by mistake: {$reason}", 0, 500),
            'category_uuid' => $category->uuid,
            'amount' => $amount,
            'origin' => Voucher::ORIGIN_RETURN_TO_COLLECTOR,
        ], $actor, branchId: $payment->customer->branch_id);
    }

    /** A pending refund bon for money going back to the customer, paid from the till once approved. */
    private function refundBon(Payment $payment, string $amount, string $reason, User $actor): Voucher
    {
        $customer = $payment->customer;
        $category = ExpenseCategory::query()->firstOrCreate(['name' => CustomerChargeService::REFUND_CATEGORY], ['icon' => 'ti-receipt-refund', 'is_active' => true]);

        return app(VoucherService::class)->request([
            'kind' => Voucher::KIND_EXPENSE,
            'payee_name' => mb_substr((string) $customer->name, 0, 150),
            'purpose' => mb_substr("Refund — payment reversed: {$customer->name} ({$customer->accountCode()}): {$reason}", 0, 500),
            'category_uuid' => $category->uuid,
            'amount' => $amount,
            'origin' => Voucher::ORIGIN_REFUND,
        ], $actor, branchId: $customer->branch_id);
    }

    /**
     * Voids a payment whose money is being handed back through a refund bon
     * (a rejected field registration — FieldInstallationService::reject()):
     * rejected and its receipt voided whatever its status, and, when it was
     * verified, marked reversed so it is never approved again. Unlike
     * reverse(), no remittance or bill-run check: the refund settles it.
     */
    public function voidForRefund(Payment $payment, string $reason, User $actor): void
    {
        $wasVerified = $payment->verification_status === 'verified';

        $this->applyDecision($payment, new VerifyPaymentData(action: 'reject', notes: $reason), $actor);

        if ($wasVerified) {
            $payment->update(['reversed_at' => Carbon::now(), 'reversed_by' => $actor->id, 'reversal_reason' => $reason]);
        }
    }

    /**
     * verify()'s write path without its per-call report-cache flush and
     * fresh() reload, so verifyMany() can run it per payment and flush the
     * report cache once for the whole batch.
     */
    private function applyDecision(Payment $payment, VerifyPaymentData $data, User $actor): void
    {
        BranchLookup::remember([$payment]);

        // The second lock behind PaymentPolicy::verify(): a possible
        // duplicate is approved by the office, never by whoever recorded it
        // (the owner excepted) — whatever path the approval came by.
        if ($data->action === 'approve' && $payment->isPossibleDuplicate()
            && (! $this->context->can('payments.verify') || ($payment->recorded_by === $actor->id && ! $this->context->isSuper()))) {
            throw ValidationException::withMessages(['payment' => 'This may repeat an earlier payment — someone else in the office must approve it.']);
        }

        DB::transaction(function () use ($payment, $data, $actor): void {
            $verification = $payment->relationLoaded('verification') && $payment->verification !== null
                ? $payment->verification
                : $this->verifications->firstOrCreateForPayment($payment->id);

            // Lets PaymentReceiptService::issueFor() reuse this row instead
            // of lazy-loading it again.
            $payment->setRelation('verification', $verification);

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

            // An installation instalment approved or rejected may settle or
            // re-open its charge (docs/plans/installation-and-promotions.md).
            if ($payment->customer_charge_id !== null) {
                $payment->loadMissing('customerCharge')->customerCharge?->refreshStatus();
            }
        });

        $this->forgetShowCache($payment->uuid);
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

        // One query for the whole selection (with everything verify() and
        // receipt issuing read) rather than a lookup per payment.
        $paymentsByUuid = $this->payments->findManyByUuids($paymentUuids, ['customer.zone.branch', 'customer.latestManuscript', 'verification', 'receipt', 'customerCharge', 'recordedBy']);
        BranchLookup::remember($paymentsByUuid);

        foreach ($paymentUuids as $uuid) {
            $payment = $paymentsByUuid->get($uuid);

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

            if ($isAgent && ! $this->context->isAgentCovering($payment->customer->zone_id)) {
                $skipped[$uuid] = 'Outside your zones.';

                continue;
            }

            // Never in bulk: someone opens it and compares it with the earlier payment.
            if ($payment->isPossibleDuplicate()) {
                $skipped[$uuid] = 'May repeat an earlier payment this month — open it to check.';

                continue;
            }

            $this->applyDecision($payment, new VerifyPaymentData(
                action: 'approve',
                notes: "Bulk-verified — amount matches {$payment->customer->name}'s standard monthly bill.",
            ), $actor);

            $verified[] = $uuid;
        }

        if ($verified !== []) {
            // See verify()'s matching comment — flushed once per batch.
            ReportService::forgetCache(Carbon::now(BusinessTimezone::WAT), TenantContext::currentBranchId());
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
        Cache::forget(PaymentService::showCacheKey($uuid, TenantContext::currentBranchId()));
        Cache::forget(PaymentService::showCacheKey($uuid, null));
    }
}
