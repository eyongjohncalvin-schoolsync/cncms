<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Agent */
class AgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'location' => $this->location,
            'phone' => $this->phone,
            'zone_uuid' => $this->whenLoaded('zone', fn () => $this->zone->uuid),
            'zone_name' => $this->whenLoaded('zone', fn () => $this->zone->name),
            'salary' => $this->salary,
            'email' => $this->email,
            'dob' => $this->dob,
            'marital_status' => $this->marital_status,
            'children' => $this->children,
            'status' => $this->status,
            'user_uuid' => $this->whenLoaded('user', fn () => $this->user?->uuid),
            'last_sync_at' => $this->last_sync_at,
            'created_at' => $this->created_at,
        ];
    }
}
