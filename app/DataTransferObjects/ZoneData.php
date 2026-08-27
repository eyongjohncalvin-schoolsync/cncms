<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for creating/updating a Zone. Built from a Form Request's
 * validated array. All properties are nullable so the same DTO can carry a
 * partial "only the fields the caller actually sent" payload for updates —
 * StoreZoneRequest guarantees `name` is present for creation.
 *
 * `branchUuid` carries the external-facing branch reference from the
 * request; resolving it to an internal `branch_id` (including the "only
 * one branch exists" default) is the Service's job, not this DTO's or the
 * Repository's — same split as CustomerData::$zoneUuid.
 */
final readonly class ZoneData
{
    public function __construct(
        public ?string $name = null,
        public ?string $town = null,
        public ?string $branchUuid = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            town: $data['town'] ?? null,
            branchUuid: $data['branch_uuid'] ?? null,
        );
    }

    /**
     * Only the attributes that were actually provided (excluding branch_id,
     * which the Service resolves and injects separately), ready to hand to
     * Eloquent's create()/update(). Filters out nulls so a partial update
     * DTO never overwrites unrelated columns with null.
     */
    public function toAttributes(): array
    {
        return array_filter([
            'name' => $this->name,
            'town' => $this->town,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
