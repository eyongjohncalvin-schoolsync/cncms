<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'phone' => $this->phone,
            'momo_number' => $this->momo_number,
            'location' => $this->location,
            'notes' => $this->notes,
            'status' => $this->status,
            'created_by' => $this->relationLoaded('creator') && $this->creator ? ['uuid' => $this->creator->uuid, 'name' => $this->creator->name] : null,
            'approved_by' => $this->relationLoaded('approver') && $this->approver ? ['uuid' => $this->approver->uuid, 'name' => $this->approver->name] : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'expenditures_count' => $this->expenditures_count ?? null,
        ];
    }
}
