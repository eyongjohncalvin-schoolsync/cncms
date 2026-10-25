<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CompensationBatch;
use App\Services\FreeMonthGrantService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /compensation — free months for a whole zone or branch
 * (App\Services\CompensationService::request()).
 */
class StoreCompensationBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CompensationBatch::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_uuid' => ['required', 'uuid', 'exists:branches,uuid'],
            'zone_uuid' => ['nullable', 'uuid', 'exists:zones,uuid'],
            'service_uuids' => ['nullable', 'array'],
            'service_uuids.*' => ['uuid', 'exists:services,uuid'],
            'months' => ['required', 'integer', 'min:1', 'max:'.FreeMonthGrantService::maxMonths()],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
