<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Company;
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
            'status_reason' => $this->status_reason,
            'status_note' => $this->status_note,
            'location' => $this->location,
            'created_at' => $this->created_at,
            // Sent for either 'disconnected' or 'suspended' (2026-08 owner
            // decision, business-rules.md section 6): the reconnection fine
            // is admin-discretion opt-in for BOTH statuses now via
            // CustomerStatusService::reconnectOne()'s $includeFine
            // parameter — there is no status-based distinction on the fine
            // anymore, so this figure represents what WOULD be charged if
            // the office chooses to include it, not an automatic charge.
            // Sourced from Company::cached()->reconnection_fine
            // (admin-configurable, defaulting to 2000.00) so the mobile
            // Reconnect & Pay screen shows a real, current figure instead of
            // a hardcoded 2,000 FCFA that could drift from what
            // Settings > Company Info has configured.
            'reconnection_fine' => in_array($this->status, ['disconnected', 'suspended'], true)
                ? (string) (Company::cached()?->reconnection_fine ?? '2000.00')
                : null,
            'manuscript' => $this->whenLoaded('latestManuscript', fn () => $this->latestManuscript ? [
                'uuid' => $this->latestManuscript->uuid,
                'bill' => $this->latestManuscript->bill,
                'total_arrears' => $this->latestManuscript->total_arrears,
                'credit' => $this->latestManuscript->credit,
                'total_bill' => $this->latestManuscript->total_bill,
                'payment_expiration' => $this->latestManuscript->payment_expiration?->toDateString(),
                'prepaid_months_remaining' => (int) $this->latestManuscript->prepaid_months_remaining,
                'prepaid_rate' => $this->latestManuscript->prepaid_rate,
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
