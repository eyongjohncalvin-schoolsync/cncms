<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for POST /arrears-adjustments/{uuid}/reject.
 * `rejectionReason` is required non-empty by RejectArrearsAdjustmentRequest
 * — a rejected request is a permanent audit artifact (this feature's design
 * doc: "not editable/resubmittable in place — a rejected request stays as
 * an audit artifact, a fresh request is a new row"), so unlike
 * complaints.resolution_notes there is no reopen-style flow that would need
 * this cleared back to null later.
 */
final readonly class RejectArrearsAdjustmentData
{
    public function __construct(
        public string $rejectionReason,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            rejectionReason: $data['rejection_reason'],
        );
    }
}
