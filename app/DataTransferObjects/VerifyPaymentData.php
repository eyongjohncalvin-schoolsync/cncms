<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for POST /payments/{uuid}/verify. Built from
 * VerifyPaymentRequest's validated array. `action` drives which branch
 * App\Services\PaymentVerificationService::verify() takes; the resulting
 * status writes (payment_verifications.status, payments.verification_status,
 * verified_by, verified_at) are computed server-side, not supplied by the
 * client, per api-spec.md section 3.4.
 */
final readonly class VerifyPaymentData
{
    public function __construct(
        public string $action,
        public ?string $momoRef = null,
        public ?string $notes = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            action: $data['action'],
            momoRef: $data['momo_ref'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }
}
