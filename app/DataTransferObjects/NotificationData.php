<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Fully-formed notification event data, built directly by
 * App\Services\NotificationService's broadcastToUser()/broadcastToRole()/
 * broadcastToAll() helpers rather than from a FormRequest's validated
 * array — unlike most other DTOs in this app, there is no public HTTP
 * endpoint for creating a notification (in-app-notifications.md's whole
 * design is that other backend features, e.g. the Complaint Desk, call
 * NotificationService as a PHP client, not an HTTP client), so there is no
 * `fromArray()` here.
 */
final readonly class NotificationData
{
    public function __construct(
        public string $type,
        public string $severity,
        public string $title,
        public string $body,
        public ?string $link = null,
        public ?string $sourceType = null,
        public ?string $sourceUuid = null,
        public string $broadcastScope = 'all',
        public ?int $recipientUserId = null,
        public ?string $recipientRole = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'source_type' => $this->sourceType,
            'source_uuid' => $this->sourceUuid,
            'broadcast_scope' => $this->broadcastScope,
            'recipient_user_id' => $this->recipientUserId,
            'recipient_role' => $this->recipientRole,
        ];
    }
}
