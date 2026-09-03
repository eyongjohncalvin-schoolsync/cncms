<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Service;
use App\Models\ServiceVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The tick-list shape returned by GET /api/v1/services (Api\
 * ServiceController) — the mobile equivalent of the web's
 * CustomerController::serviceCatalogue() array. Same field names as that
 * array so the two clients never drift apart.
 *
 * @mixin Service
 */
class ServiceCatalogueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'is_default' => $this->is_default,
            'variants' => $this->variants->map(fn (ServiceVariant $variant): array => [
                'uuid' => $variant->uuid,
                'name' => $variant->name,
                'price' => $variant->price,
            ])->values()->all(),
        ];
    }
}
