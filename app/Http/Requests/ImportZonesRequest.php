<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Zone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk zone import via zone_upload.xlsx — gated by the same 'create'
 * ability as a manually-typed zone (ZonePolicy::create(), super/admin/
 * manager), since importing is just a faster way to do the same thing a
 * form submission does. Row-level field validation happens inside
 * App\Services\ZoneImportService (reusing StoreZoneRequest::rules()
 * per row) — this request only gates the upload itself.
 */
class ImportZonesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Zone::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 5MB is generous for a spreadsheet of a few hundred rows
            // (max is in kilobytes per Laravel's `max` file rule).
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ];
    }
}
