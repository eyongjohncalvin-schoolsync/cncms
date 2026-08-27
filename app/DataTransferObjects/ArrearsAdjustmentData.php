<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for POST /arrears-adjustments. Built from
 * StoreArrearsAdjustmentRequest's validated array — same shape convention as
 * ComplaintData. `customerUuid` resolves to an internal `customer_id` in
 * App\Services\ArrearsAdjustmentService::create(); `requested_by` and
 * `arrears_snapshot` are NOT represented here — the Service resolves and
 * injects both itself (the former from the authenticated actor, the latter
 * from the customer's current manuscript at request time), matching
 * ComplaintData's customer_id/zone_id split.
 */
final readonly class ArrearsAdjustmentData
{
    public function __construct(
        public string $customerUuid,
        public string $targetPeriod,
        public string $direction,
        public string $amount,
        public string $reasonCategory,
        public string $reasonNote,
        public ?string $complaintUuid = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            customerUuid: $data['customer_uuid'],
            targetPeriod: $data['target_period'],
            direction: $data['direction'],
            amount: (string) $data['amount'],
            reasonCategory: $data['reason_category'],
            reasonNote: $data['reason_note'],
            complaintUuid: $data['complaint_uuid'] ?? null,
        );
    }

    /**
     * Attributes ready for mass assignment — excludes customer_id/complaint_id
     * (resolved from the *_uuid fields) and requested_by/arrears_snapshot,
     * which the Service resolves and injects separately.
     */
    public function toAttributes(): array
    {
        return [
            'target_period' => $this->targetPeriod,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'reason_category' => $this->reasonCategory,
            'reason_note' => $this->reasonNote,
        ];
    }
}
