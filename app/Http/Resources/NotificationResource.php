<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Notification
 *
 * `read_at`/`acknowledged_at` are not real columns on the Notification
 * model itself — they're the requesting user's own state, extra-selected
 * as `recipient_read_at`/`recipient_acknowledged_at` by
 * App\Repositories\Eloquent\NotificationRepository::recentForUser()/
 * unacknowledgedEmergenciesForUser(). Read directly off the model's
 * dynamic attributes here rather than via a relation load, since they're
 * always exactly one value (this user's own row, or none).
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'source_type' => $this->source_type,
            'source_uuid' => $this->source_uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'read_at' => $this->parseNullableTimestamp($this->recipient_read_at ?? null),
            'acknowledged_at' => $this->parseNullableTimestamp($this->recipient_acknowledged_at ?? null),
        ];
    }

    private function parseNullableTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Carbon ? $value->toIso8601String() : Carbon::parse($value)->toIso8601String();
    }
}
