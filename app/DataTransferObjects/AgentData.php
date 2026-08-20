<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Validated input for creating/updating an Agent. Built from a Form
 * Request's validated array.
 *
 * `zoneUuid`/`userUuid` carry external-facing references; resolving them to
 * internal `zone_id`/`user_id` is the Service's job. `sync_token` and
 * `last_sync_at` are system-managed by the offline sync flow and are
 * deliberately not represented here.
 */
final readonly class AgentData
{
    public function __construct(
        public ?string $zoneUuid = null,
        public ?string $userUuid = null,
        public ?string $name = null,
        public ?string $location = null,
        public ?string $phone = null,
        public ?string $salary = null,
        public ?string $email = null,
        public ?string $dob = null,
        public ?string $maritalStatus = null,
        public ?int $children = null,
        public ?string $status = null,
        public ?string $picture = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            zoneUuid: $data['zone_uuid'] ?? null,
            userUuid: $data['user_uuid'] ?? null,
            name: $data['name'] ?? null,
            location: $data['location'] ?? null,
            phone: $data['phone'] ?? null,
            salary: array_key_exists('salary', $data) && $data['salary'] !== null ? (string) $data['salary'] : null,
            email: $data['email'] ?? null,
            dob: $data['dob'] ?? null,
            maritalStatus: $data['marital_status'] ?? null,
            children: array_key_exists('children', $data) && $data['children'] !== null ? (int) $data['children'] : null,
            status: $data['status'] ?? null,
            picture: $data['picture'] ?? null,
        );
    }

    /**
     * Only the attributes that were actually provided (excluding zone_id
     * and user_id, which the Service resolves and injects separately).
     */
    public function toAttributes(): array
    {
        return array_filter([
            'name' => $this->name,
            'location' => $this->location,
            'phone' => $this->phone,
            'salary' => $this->salary,
            'email' => $this->email,
            'dob' => $this->dob,
            'marital_status' => $this->maritalStatus,
            'children' => $this->children,
            'status' => $this->status,
            'picture' => $this->picture,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
