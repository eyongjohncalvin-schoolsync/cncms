<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('zone'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:25',
                Rule::unique('zones', 'name')->ignore($this->route('zone')),
            ],
            // Branches v2: a fenced manager's zones are always in their own branch.
            'branch_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:branches,uuid', Rule::when(fn (): bool => app(TenantContext::class)->isFenced(), ['missing'])],
        ];
    }
}
