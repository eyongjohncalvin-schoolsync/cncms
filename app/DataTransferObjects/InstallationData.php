<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The add-customer form's "Installation & first payment" section
 * (docs/plans/installation-and-promotions.md, phase 2 + revision D15–D19),
 * validated.
 *
 * `fee` null = the offer's fee. `servicePlans` null = the offer's preset (or
 * nothing when no offer is picked); otherwise, per service ticked, the free
 * months chosen (a consecutive run from `free_from`, D16) and the months
 * paid upfront that follow them (D15). Anything below the offer's fee or
 * beyond its months / the company cap needs `reason` and admin/owner
 * approval (D8, D19), as do free months when the installation is not fully
 * paid at registration (D17).
 */
final readonly class InstallationData
{
    /**
     * @param  array<string, array{free_from: ?string, free_months: int, paid_months: int}>|null  $servicePlans  service uuid => plan
     */
    public function __construct(
        public ?string $offerUuid = null,
        public ?string $fee = null,
        public string $paidNow = '0',
        public ?array $servicePlans = null,
        public ?string $connectedOn = null,
        public ?string $reason = null,
        public ?string $method = null,
        public ?string $methodReference = null,
        // Auditing a late registration (owner, 2026-10-26): who really
        // connected the customer, and what the customer says they paid and
        // to whom (0 = nobody; asked only when registered late).
        public ?string $connectedByUuid = null,
        public ?string $customerSaysPaidAmount = null,
        public ?string $customerSaysPaidTo = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $plans = null;

        if (isset($data['service_plans']) && is_array($data['service_plans'])) {
            $plans = [];

            foreach ($data['service_plans'] as $row) {
                if (is_array($row) && isset($row['service_uuid'])) {
                    $plans[(string) $row['service_uuid']] = [
                        'free_from' => isset($row['free_from']) && $row['free_from'] !== '' ? (string) $row['free_from'] : null,
                        'free_months' => (int) ($row['free_months'] ?? 0),
                        'paid_months' => (int) ($row['paid_months'] ?? 0),
                    ];
                }
            }
        }

        $reason = isset($data['installation_reason']) ? trim((string) $data['installation_reason']) : '';
        $reference = isset($data['method_reference']) ? trim((string) $data['method_reference']) : '';

        return new self(
            offerUuid: $data['installation_offer_uuid'] ?? null,
            fee: isset($data['installation_fee']) && $data['installation_fee'] !== '' ? (string) $data['installation_fee'] : null,
            paidNow: isset($data['installation_paid']) && $data['installation_paid'] !== '' ? (string) $data['installation_paid'] : '0',
            servicePlans: $plans,
            connectedOn: $data['connected_on'] ?? null,
            reason: $reason !== '' ? $reason : null,
            method: $data['method'] ?? null,
            methodReference: $reference !== '' ? $reference : null,
            connectedByUuid: isset($data['connected_by_uuid']) && $data['connected_by_uuid'] !== '' ? (string) $data['connected_by_uuid'] : null,
            customerSaysPaidAmount: isset($data['customer_says_paid_amount']) && $data['customer_says_paid_amount'] !== '' ? (string) $data['customer_says_paid_amount'] : null,
            customerSaysPaidTo: isset($data['customer_says_paid_to']) && trim((string) $data['customer_says_paid_to']) !== '' ? trim((string) $data['customer_says_paid_to']) : null,
        );
    }

    /** Whether the form filled in anything in the section at all. */
    public function isEmpty(): bool
    {
        return $this->offerUuid === null
            && $this->fee === null
            && bccomp($this->paidNow, '0', 2) === 0
            && $this->servicePlans === null;
    }

    /** Months paid upfront on any service (a payment is taken). */
    public function paysUpfront(): bool
    {
        foreach ($this->servicePlans ?? [] as $plan) {
            if ($plan['paid_months'] > 0) {
                return true;
            }
        }

        return false;
    }
}
