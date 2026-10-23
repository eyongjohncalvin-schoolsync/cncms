<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Zone;
use App\Services\ZoneService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /zones/bulk-delete/preview — the dry run behind the bulk delete
 * confirmation modal. Selection is either an explicit `zone_uuids` list
 * (checked rows) or `all_matching` plus the page's current `search` filter
 * (the "select all N matching" path, across every page). Same gate as the
 * commit (BulkDeleteZonesRequest).
 */
class PreviewBulkDeleteZonesRequest extends FormRequest
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
            'zone_uuids' => ['sometimes', 'array', 'max:'.ZoneService::BULK_DELETE_LIMIT],
            'zone_uuids.*' => ['required', 'uuid', 'distinct'],
            'all_matching' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zone_uuids.max' => 'At most '.ZoneService::BULK_DELETE_LIMIT.' zones can be deleted at once.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (empty($this->input('zone_uuids')) && ! $this->boolean('all_matching')) {
                $validator->errors()->add('zone_uuids', 'Select at least one zone to delete.');
            }
        });
    }
}
