<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Zone */
class ZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            // Kept for apps built before zones lost their own town: now the branch's.
            'town' => $this->branchName(),
            'branch_name' => $this->branchName(),
            'customer_count' => $this->whenCounted('customers'),
            'agent' => $this->whenLoaded('agents', function () {
                $agent = $this->agents->first();

                return $agent ? [
                    'uuid' => $agent->uuid,
                    'name' => $agent->name,
                    'phone' => $agent->phone,
                ] : null;
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
