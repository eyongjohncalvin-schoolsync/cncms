<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * One ticked row on the customer add/edit form: which service (by uuid),
 * optionally which variant of it (services.md section 4 — e.g. a specific
 * TV channel broadcast), and the price to charge this customer for it.
 * A row with `serviceVariantUuid` set represents a variant subscription and
 * requires a sibling row for the same service with `serviceVariantUuid`
 * null (the base subscription) — enforced by
 * App\Services\CustomerSubscriptionService::sync(), not here. Part of
 * CustomerData::$services — see services.md section 5.
 */
final readonly class CustomerServiceSelection
{
    public function __construct(
        public string $serviceUuid,
        public string $price,
        public ?string $serviceVariantUuid = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $variantUuid = $data['service_variant_uuid'] ?? null;

        return new self(
            serviceUuid: (string) ($data['service_uuid'] ?? ''),
            price: (string) ($data['price'] ?? '0'),
            serviceVariantUuid: $variantUuid !== null && $variantUuid !== '' ? (string) $variantUuid : null,
        );
    }
}
