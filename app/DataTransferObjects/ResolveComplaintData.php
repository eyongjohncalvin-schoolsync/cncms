<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for POST /complaints/{uuid}/resolve. `resolutionNotes` is
 * required non-empty by ResolveComplaintRequest even though
 * complaints.resolution_notes is nullable — nullable so a reopen can clear
 * it (see App\Services\ComplaintService::reopen()).
 */
final readonly class ResolveComplaintData
{
    public function __construct(
        public string $resolutionNotes,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            resolutionNotes: $data['resolution_notes'],
        );
    }
}
