<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Response shape for GET /api/v1/agents/me — the currently-authenticated
 * agent's own record, and only their own record (see
 * App\Http\Controllers\Api\AgentController::me()). Deliberately a separate
 * Resource from AgentResource (the roster index/show shape) rather than
 * widening that shared shape: AgentResource is also used by the multi-agent
 * roster endpoints (AgentPolicy::viewAny()/view() are open to every tenant
 * role), and `picture_url` — appropriate for an agent to see about
 * themselves — has no reason to be added to a shape other agents/office
 * staff can also request for someone else.
 *
 * @mixin Agent
 */
class AgentMeResource extends JsonResource
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
            'picture_url' => $this->picture ? Storage::disk('public')->url($this->picture) : null,
            'last_sync_at' => $this->last_sync_at,
            'created_at' => $this->created_at,
        ];
    }
}
