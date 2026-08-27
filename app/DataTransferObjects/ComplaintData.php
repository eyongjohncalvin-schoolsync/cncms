<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for POST /complaints. Built from StoreComplaintRequest's
 * validated array — same shape convention as ExpenditureData/PaymentData.
 *
 * `customerUuid` carries the external-facing customer reference for
 * `category = 'customer'` complaints; resolving it to an internal
 * `customer_id` (and deriving `zone_id` — never user-entered, see
 * references/complaint-desk.md section 2) is
 * App\Services\ComplaintService::create()'s job, mirroring
 * ExpenditureService::resolveCategoryId(). `submitted_by` is likewise NOT
 * represented here — it is passed explicitly into
 * App\Repositories\Eloquent\ComplaintRepository::create() rather than read
 * from auth() internally, matching ExpenditureData's $userId convention, so
 * a future mobile/offline submission path (references/complaint-desk.md
 * section 7) can supply it the same way Expenditure's sync path does.
 *
 * `collectedAt` mirrors PaymentData's identically-named property: the field
 * agent's actual offline-submission timestamp, only ever populated by
 * App\Services\SyncService::pushComplaint() (from the client's `created_at`
 * wire field — see that method's doc comment) and stored as
 * `complaints.collected_at`, deliberately NOT as `created_at` itself.
 */
final readonly class ComplaintData
{
    public function __construct(
        public string $category,
        public string $title,
        public string $description,
        public bool $urgent = false,
        public ?string $customerUuid = null,
        public ?string $localUuid = null,
        public ?string $collectedAt = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            category: $data['category'],
            title: $data['title'],
            description: $data['description'],
            urgent: (bool) ($data['urgent'] ?? false),
            customerUuid: $data['customer_uuid'] ?? null,
            localUuid: $data['local_uuid'] ?? null,
            collectedAt: $data['collected_at'] ?? null,
        );
    }

    /**
     * Attributes ready for mass assignment — excludes customer_id/zone_id,
     * which the Service resolves and injects separately.
     */
    public function toAttributes(): array
    {
        return [
            'category' => $this->category,
            'title' => $this->title,
            'description' => $this->description,
            'urgent' => $this->urgent,
            'local_uuid' => $this->localUuid,
            'collected_at' => $this->collectedAt,
        ];
    }
}
