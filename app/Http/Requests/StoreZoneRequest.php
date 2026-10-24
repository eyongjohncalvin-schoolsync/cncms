<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Zone;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreZoneRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:25', 'unique:zones,name'],
            // Branches v2: a fenced manager's zones are always in their own branch.
            'branch_uuid' => ['nullable', 'uuid', 'exists:branches,uuid', Rule::when(fn (): bool => app(TenantContext::class)->isFenced(), ['missing'])],
        ];
    }
}
