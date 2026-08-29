<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'customer_uuid' => $this->whenLoaded('customer', fn () => $this->customer->uuid),
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer->name),
            'amount' => $this->amount,
            'credit' => $this->credit,
            'frequency' => $this->frequency,
            'months' => $this->months,
            'expiration_date' => $this->expiration_date?->toDateString(),
            // Draw-down (references/prepayment-drawdown.md): rate locked at
            // payment time on a months/yearly prepayment; the agent's
            // pay-down-arrears-first toggle.
            'prepaid_rate' => $this->prepaid_rate,
            'clear_arrears_first' => (bool) $this->clear_arrears_first,
            'verification_status' => $this->verification_status,
            'recorded_offline' => $this->recorded_offline,
            'created_at' => $this->created_at,
            'processed_at' => $this->processed_at,
            'verification' => $this->whenLoaded('verification', fn () => new PaymentVerificationResource($this->verification)),
        ];
    }
}
