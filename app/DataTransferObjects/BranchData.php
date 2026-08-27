<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for creating a Branch. Built from a Form Request's
 * validated array — mirrors ZoneData's shape for a single-field resource.
 */
final readonly class BranchData
{
    public function __construct(
        public ?string $name = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return array_filter([
            'name' => $this->name,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
