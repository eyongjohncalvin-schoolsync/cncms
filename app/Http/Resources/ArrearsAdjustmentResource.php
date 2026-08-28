<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ArrearsAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArrearsAdjustment
 *
 * JSON shape for Api\ArrearsAdjustmentController::store()'s response —
 * mirrors ComplaintResource's structure/conventions. Only the fields a
 * mobile REQUEST confirmation screen needs are exposed; approval/rejection
 * fields (approved_by, second_approved_by, rejection_reason, processed_at,
 * ...) are deliberately omitted since this resource is never used to render
 * a review/approve surface — that stays web-only (see the controller's own
 * class doc).
 */
class ArrearsAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'customer_uuid' => $this->whenLoaded('customer', fn () => $this->customer?->uuid),
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'target_period' => $this->target_period,
            'direction' => $this->direction,
            'amount' => (string) $this->amount,
            'reason_category' => $this->reason_category,
            'reason_note' => $this->reason_note,
            'arrears_snapshot' => (string) $this->arrears_snapshot,
            'status' => $this->status,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'uuid' => $this->requestedBy->uuid,
                'name' => $this->requestedBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
