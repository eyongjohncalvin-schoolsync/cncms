<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for creating/updating a Customer. Built from a Form
 * Request's validated array. All properties are nullable so the same DTO
 * shape carries both a full "create" payload and a partial "only the fields
 * the caller actually sent" payload for updates.
 *
 * `zoneUuid` carries the external-facing zone reference from the request;
 * resolving it to an internal `zone_id` is the Service's job, not this DTO's
 * or the Repository's — the DTO only represents validated input as-is.
 */
final readonly class CustomerData
{
    /**
     * @param  list<CustomerServiceSelection>|null  $services  null = "don't
     *                                                         touch this customer's subscriptions" (bulk bill update, status
     *                                                         changes, generic partial edits). A (possibly empty) array =
     *                                                         the full desired set (add/edit form) — App\Services\
     *                                                         CustomerSubscriptionService::sync() diffs the pivot to match
     *                                                         it and recomputes `bill`.
     */
    public function __construct(
        public ?string $zoneUuid = null,
        public ?string $name = null,
        public ?string $location = null,
        public ?string $bill = null,
        public ?string $others = null,
        public ?string $phone = null,
        public ?string $description = null,
        public ?string $level = null,
        public ?string $status = null,
        public ?array $services = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            zoneUuid: $data['zone_uuid'] ?? null,
            name: $data['name'] ?? null,
            location: $data['location'] ?? null,
            bill: array_key_exists('bill', $data) && $data['bill'] !== null ? (string) $data['bill'] : null,
            others: array_key_exists('others', $data) && $data['others'] !== null ? (string) $data['others'] : null,
            phone: $data['phone'] ?? null,
            description: $data['description'] ?? null,
            level: $data['level'] ?? null,
            status: $data['status'] ?? null,
            services: array_key_exists('services', $data) && is_array($data['services'])
                ? array_values(array_map(
                    static fn ($selection): CustomerServiceSelection => $selection instanceof CustomerServiceSelection
                        ? $selection
                        : CustomerServiceSelection::fromArray((array) $selection),
                    $data['services'],
                ))
                : null,
        );
    }

    /**
     * Only the attributes that were actually provided (excluding zone_id,
     * which the Service resolves and injects separately), ready to hand to
     * Eloquent's create()/update().
     */
    public function toAttributes(): array
    {
        return array_filter([
            'name' => $this->name,
            'location' => $this->location,
            'bill' => $this->bill,
            'others' => $this->others,
            'phone' => $this->phone,
            'description' => $this->description,
            'level' => $this->level,
            'status' => $this->status,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
