<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Manuscript;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Manuscript */
class ManuscriptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'customer_uuid' => $this->whenLoaded('customer', fn () => $this->customer->uuid),
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer->name),
            'zone_name' => $this->whenLoaded('customer', fn () => $this->customer->zone?->name),
            'bill' => $this->bill,
            'total_arrears' => $this->total_arrears,
            'credit' => $this->credit,
            'total_bill' => $this->total_bill,
            'payment_expiration' => $this->payment_expiration?->toDateString(),
            'period' => $this->period,
            'status' => $this->whenLoaded('customer', fn () => $this->customer->status),
        ];
    }
}
