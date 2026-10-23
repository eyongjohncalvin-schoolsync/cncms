<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The customer page's "Relocate" / "Re-install" form, validated
 * (relocation and re-installation fees, docs/plans/installation-and-promotions.md
 * "Out of scope / later").
 *
 * `fee` null = the company's default fee. A fee below the default needs
 * `reason` and admin/owner approval; a fee of 0 against a default asks for
 * the default to be waived (reason + approval) — the same rules as an
 * installation fee (D8). `toZoneUuid` / `toLocation` are relocation only
 * (null zone = the customer stays in their zone).
 */
final readonly class OneOffChargeData
{
    public function __construct(
        public ?string $fee = null,
        public string $paidNow = '0',
        public ?string $method = null,
        public ?string $methodReference = null,
        public ?string $reason = null,
        public ?string $note = null,
        public ?string $toZoneUuid = null,
        public ?string $toLocation = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $trimmed = fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return new self(
            fee: isset($data['fee']) && $data['fee'] !== '' ? number_format((float) $data['fee'], 2, '.', '') : null,
            paidNow: number_format((float) ($data['paid_now'] ?? 0), 2, '.', ''),
            method: $trimmed('method'),
            methodReference: $trimmed('method_reference'),
            reason: $trimmed('reason'),
            note: $trimmed('note'),
            toZoneUuid: $trimmed('zone_uuid'),
            toLocation: $trimmed('location'),
        );
    }
}
