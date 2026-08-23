<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\PaymentVerification;

interface PaymentVerificationRepositoryInterface
{
    public function findByPaymentId(int $paymentId): ?PaymentVerification;

    /**
     * Returns the existing payment_verifications row for this payment, or
     * creates a new `status = pending` row if none exists yet — a payment
     * can be verified (or have a receipt attached) without ever having had
     * a verification row created first.
     */
    public function firstOrCreateForPayment(int $paymentId): PaymentVerification;

    public function update(PaymentVerification $verification, array $attributes): PaymentVerification;
}
