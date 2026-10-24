<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A branch's own contact details, MoMo number and fees (branches v2 D4).
 * Every field is optional: left empty, the branch uses headquarters' value.
 */
class UpdateBranchSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', $this->route('branch'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'];

        return [
            'code' => ['nullable', 'string', 'max:10', 'alpha_dash', Rule::unique('branches', 'code')->ignore($this->route('branch'))],
            'location' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'tech_number' => ['nullable', 'string', 'max:30'],
            'momo_number' => ['nullable', 'string', 'max:30'],
            'momo_name' => ['nullable', 'string', 'max:50'],
            'reconnection_fine' => $money,
            'relocation_fee' => $money,
            'reinstallation_fee' => $money,
            // Headquarters' rule for the branch — never set by the branch itself.
            'sends_to_hq' => ['sometimes', Rule::in(Branch::SENDS_TO_HQ), Rule::when(fn (): bool => ! $this->user()->can('update', $this->route('branch')), ['missing'])],
        ];
    }
}
