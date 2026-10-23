<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The add-customer form's "Installation & first payment" section
 * (docs/plans/installation-and-promotions.md, phase 2, item 5), validated.
 *
 * `fee` null = the offer's fee. `freeMonths` null = the offer's months;
 * otherwise service uuid => months (0 = none). Anything below the offer's
 * fee or beyond its months needs `reason` and admin/owner approval (D8).
 */
final readonly class InstallationData
{
    /**
     * @param  array<string, int>|null  $freeMonths
     */
    public function __construct(
        public ?string $offerUuid = null,
        public ?string $fee = null,
        public string $paidNow = '0',
        public ?array $freeMonths = null,
        public int $advanceMonths = 0,
        public ?string $connectedOn = null,
        public ?string $reason = null,
        public ?string $method = null,
        public ?string $methodReference = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $freeMonths = null;

        if (isset($data['free_months']) && is_array($data['free_months'])) {
            $freeMonths = [];

            foreach ($data['free_months'] as $row) {
                if (is_array($row) && isset($row['service_uuid'])) {
                    $freeMonths[(string) $row['service_uuid']] = (int) ($row['months'] ?? 0);
                }
            }
        }

        $reason = isset($data['installation_reason']) ? trim((string) $data['installation_reason']) : '';
        $reference = isset($data['method_reference']) ? trim((string) $data['method_reference']) : '';

        return new self(
            offerUuid: $data['installation_offer_uuid'] ?? null,
            fee: isset($data['installation_fee']) && $data['installation_fee'] !== '' ? (string) $data['installation_fee'] : null,
            paidNow: isset($data['installation_paid']) && $data['installation_paid'] !== '' ? (string) $data['installation_paid'] : '0',
            freeMonths: $freeMonths,
            advanceMonths: (int) ($data['advance_months'] ?? 0),
            connectedOn: $data['connected_on'] ?? null,
            reason: $reason !== '' ? $reason : null,
            method: $data['method'] ?? null,
            methodReference: $reference !== '' ? $reference : null,
        );
    }

    /** Whether the form filled in anything in the section at all. */
    public function isEmpty(): bool
    {
        return $this->offerUuid === null
            && $this->fee === null
            && bccomp($this->paidNow, '0', 2) === 0
            && $this->freeMonths === null
            && $this->advanceMonths === 0;
    }
}
