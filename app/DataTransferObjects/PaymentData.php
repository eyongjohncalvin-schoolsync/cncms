<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for creating/updating a Payment. Built from a Form
 * Request's validated array.
 *
 * `customerUuid` carries the external-facing customer reference; resolving
 * it to an internal `customer_id` is the Service's job. `expiration_date`
 * and `verification_status` are deliberately NOT represented here — both
 * are computed server-side by App\Services\PaymentService (expiration from
 * frequency/months, verification_status from the caller's tenant role), not
 * supplied directly by the client, per api-spec.md section 3.2.
 *
 * `localUuid` is the client-generated idempotency key for offline sync (see
 * App\Services\SyncService::pushPayment()) — only ever populated by that
 * caller; a regular (non-sync) StorePaymentRequest payload has no
 * `local_uuid` field, so this stays null for every other create() call
 * site.
 *
 * `collectedAt` is the field agent's actual offline-collection timestamp,
 * likewise only ever populated by SyncService::pushPayment() (from the
 * client's `created_at` wire field — see that method's doc comment for why
 * it is renamed on the way in) and stored as `payments.collected_at`,
 * deliberately NOT as `created_at` itself, which keeps its existing
 * server-arrival meaning for every caller.
 */
final readonly class PaymentData
{
    public function __construct(
        public ?string $customerUuid = null,
        public ?string $amount = null,
        public ?string $credit = null,
        public ?string $frequency = null,
        public ?int $months = null,
        public ?bool $recordedOffline = null,
        public ?string $recordedByDevice = null,
        public ?string $localUuid = null,
        public ?string $collectedAt = null,
        // Draw-down (references/prepayment-drawdown.md Q1): the agent's
        // toggle — a months/yearly payment pays down outstanding arrears
        // before buying prepaid months. Ignored for `monthly`.
        public ?bool $clearArrearsFirst = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            customerUuid: $data['customer_uuid'] ?? null,
            amount: array_key_exists('amount', $data) && $data['amount'] !== null ? (string) $data['amount'] : null,
            credit: array_key_exists('credit', $data) && $data['credit'] !== null ? (string) $data['credit'] : null,
            frequency: $data['frequency'] ?? null,
            months: array_key_exists('months', $data) && $data['months'] !== null ? (int) $data['months'] : null,
            recordedOffline: array_key_exists('recorded_offline', $data) ? (bool) $data['recorded_offline'] : null,
            recordedByDevice: $data['recorded_by_device'] ?? null,
            localUuid: $data['local_uuid'] ?? null,
            collectedAt: $data['collected_at'] ?? null,
            clearArrearsFirst: array_key_exists('clear_arrears_first', $data) ? (bool) $data['clear_arrears_first'] : null,
        );
    }

    /**
     * Only the attributes that were actually provided (excluding
     * customer_id, expiration_date and verification_status, which the
     * Service resolves/computes and injects separately).
     */
    public function toAttributes(): array
    {
        return array_filter([
            'amount' => $this->amount,
            'credit' => $this->credit,
            'frequency' => $this->frequency,
            'months' => $this->months,
            'clear_arrears_first' => $this->clearArrearsFirst,
            'recorded_offline' => $this->recordedOffline,
            'recorded_by_device' => $this->recordedByDevice,
            'local_uuid' => $this->localUuid,
            'collected_at' => $this->collectedAt,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
