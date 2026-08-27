<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for creating/updating an Expenditure. Built from a Form
 * Request's validated array — same shape convention as PaymentData.
 *
 * `categoryUuid` carries the external-facing category reference; resolving
 * it to an internal `category_id` is App\Services\ExpenditureService's job
 * (see resolveCategoryId(), mirroring PaymentService::resolveCustomerId()).
 * `userId` is likewise NOT represented here — it is passed explicitly into
 * ExpenditureService::create() rather than read from auth() internally,
 * because the Sync API calls that method on behalf of an agent whose ID it
 * already has for offline-recorded expenditures.
 *
 * Fixed interface contract — the Sync API depends on this exact shape.
 * Do not change constructor property names/order or the fromArray()/
 * toAttributes() behaviour without coordinating with that work.
 */
final readonly class ExpenditureData
{
    public function __construct(
        public ?string $categoryUuid = null,
        public ?string $amount = null,
        public ?string $description = null,
        public ?string $spentAt = null,
        public ?string $notes = null,
        public ?bool $recordedOffline = null,
        public ?string $recordedByDevice = null,
        public ?string $localUuid = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            categoryUuid: $data['category_uuid'] ?? null,
            amount: array_key_exists('amount', $data) && $data['amount'] !== null ? (string) $data['amount'] : null,
            description: $data['description'] ?? null,
            spentAt: $data['spent_at'] ?? null,
            notes: $data['notes'] ?? null,
            recordedOffline: array_key_exists('recorded_offline', $data) ? (bool) $data['recorded_offline'] : null,
            recordedByDevice: $data['recorded_by_device'] ?? null,
            localUuid: $data['local_uuid'] ?? null,
        );
    }

    /**
     * Only the attributes that were actually provided (excluding
     * category_id, which the Service resolves and injects separately).
     */
    public function toAttributes(): array
    {
        return array_filter([
            'amount' => $this->amount,
            'description' => $this->description,
            'spent_at' => $this->spentAt,
            'notes' => $this->notes,
            'recorded_offline' => $this->recordedOffline,
            'recorded_by_device' => $this->recordedByDevice,
            'local_uuid' => $this->localUuid,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
