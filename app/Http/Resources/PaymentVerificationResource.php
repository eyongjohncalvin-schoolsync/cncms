<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PaymentVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin PaymentVerification */
class PaymentVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'receipt_photo_url' => $this->receipt_photo_path ? Storage::disk('public')->url($this->receipt_photo_path) : null,
            'momo_ref' => $this->momo_ref,
            'momo_status' => $this->momo_status,
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier?->name),
            'verified_at' => $this->verified_at,
            'notes' => $this->notes,
        ];
    }
}
