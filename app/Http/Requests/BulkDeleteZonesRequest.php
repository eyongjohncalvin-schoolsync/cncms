<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Zone;
use App\Services\ZoneService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /zones/bulk-delete — an explicit list of zone uuids (the ones the
 * Zones page's confirmation modal showed). Same 'delete' gate as a single
 * zone delete (ZonePolicy::delete(), `zones.manage`); ZoneController::
 * bulkDestroy() additionally authorizes each resolved zone. Unknown or
 * out-of-branch uuids are refused by ZoneService::resolveForBulkDelete().
 */
class BulkDeleteZonesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', Zone::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuids' => ['required', 'array', 'min:1', 'max:'.ZoneService::BULK_DELETE_LIMIT],
            'zone_uuids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zone_uuids.required' => 'Select at least one zone to delete.',
            'zone_uuids.min' => 'Select at least one zone to delete.',
            'zone_uuids.max' => 'At most '.ZoneService::BULK_DELETE_LIMIT.' zones can be deleted at once.',
        ];
    }
}
