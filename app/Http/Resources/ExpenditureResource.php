<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Expenditure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Expenditure */
class ExpenditureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'category_uuid' => $this->whenLoaded('category', fn () => $this->category->uuid),
            'category_name' => $this->whenLoaded('category', fn () => $this->category->name),
            'amount' => $this->amount,
            'description' => $this->description,
            'spent_at' => $this->spent_at?->toDateString(),
            'receipt_url' => $this->receipt_path ? Storage::disk('public')->url($this->receipt_path) : null,
            'notes' => $this->notes,
            'recorded_offline' => $this->recorded_offline,
            'recorded_by' => $this->whenLoaded('user', fn () => [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
