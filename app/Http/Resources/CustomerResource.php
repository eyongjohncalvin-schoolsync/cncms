<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
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
            'zone_uuid' => $this->whenLoaded('zone', fn () => $this->zone->uuid),
            'zone_name' => $this->whenLoaded('zone', fn () => $this->zone->name),
            'bill' => $this->bill,
            'others' => $this->others,
            'level' => $this->level,
            'status' => $this->status,
            'location' => $this->location,
            'created_at' => $this->created_at,
            'manuscript' => $this->whenLoaded('latestManuscript', fn () => $this->latestManuscript ? [
                'uuid' => $this->latestManuscript->uuid,
                'bill' => $this->latestManuscript->bill,
                'total_arrears' => $this->latestManuscript->total_arrears,
                'credit' => $this->latestManuscript->credit,
                'total_bill' => $this->latestManuscript->total_bill,
                'payment_expiration' => $this->latestManuscript->payment_expiration,
                'period' => $this->latestManuscript->period,
            ] : null),
            'recent_payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'uuid' => $payment->uuid,
                'amount' => $payment->amount,
                'frequency' => $payment->frequency,
                'verification_status' => $payment->verification_status,
                'created_at' => $payment->created_at,
            ])),
        ];
    }
}
