<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Complaint */
class ComplaintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'category' => $this->category,
            'title' => $this->title,
            'description' => $this->description,
            'urgent' => $this->urgent,
            'status' => $this->status,
            'customer_uuid' => $this->whenLoaded('customer', fn () => $this->customer?->uuid),
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'zone_name' => $this->whenLoaded('zone', fn () => $this->zone?->name)
                ?? $this->whenLoaded('customer', fn () => $this->customer?->zone?->name),
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy ? [
                'uuid' => $this->submittedBy->uuid,
                'name' => $this->submittedBy->name,
            ] : null),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'uuid' => $this->assignedTo->uuid,
                'name' => $this->assignedTo->name,
            ] : null),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => $this->resolvedBy ? [
                'uuid' => $this->resolvedBy->uuid,
                'name' => $this->resolvedBy->name,
            ] : null),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            // Populated by the escalation engine (App\Services\
            // ComplaintEscalationService, references/task-scheduler.md
            // section 5) once this complaint has been open 48 hours.
            'escalated_at' => $this->escalated_at?->toIso8601String(),
            'duplicate_of_uuid' => $this->whenLoaded('duplicateOf', fn () => $this->duplicateOf?->uuid),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
