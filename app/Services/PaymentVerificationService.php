<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\VerifyPaymentData;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\User;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\PaymentVerificationRepositoryInterface;
use Illuminate\Support\Facades\DB;

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
            } else {
                $this->verifications->update($verification, [
                    'status' => 'rejected',
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                    'notes' => $data->notes,
                ]);

                $this->payments->update($payment, ['verification_status' => 'rejected']);
            }
        });

        return $payment->fresh(['customer', 'verification.verifier']);
    }

    public function attachReceipt(Payment $payment, string $storedPath): PaymentVerification
    {
        $verification = $this->verifications->firstOrCreateForPayment($payment->id);

        return $this->verifications->update($verification, ['receipt_photo_path' => $storedPath]);
    }
}
