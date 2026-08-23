<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\PaymentVerification;
use App\Repositories\Contracts\PaymentVerificationRepositoryInterface;

class PaymentVerificationRepository implements PaymentVerificationRepositoryInterface
{
    public function findByPaymentId(int $paymentId): ?PaymentVerification
    {
        return PaymentVerification::query()->where('payment_id', $paymentId)->first();
    }

    public function firstOrCreateForPayment(int $paymentId): PaymentVerification
    {
        return PaymentVerification::query()->firstOrCreate(
            ['payment_id' => $paymentId],
            ['status' => 'pending'],
        );
    }

    public function update(PaymentVerification $verification, array $attributes): PaymentVerification
    {
        $verification->update($attributes);

        return $verification;
    }
}
