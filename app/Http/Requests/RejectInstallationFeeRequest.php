<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /installation-charges/{customerCharge}/fee/reject.
 * App\Policies\CustomerChargePolicy::rejectFee() enforces maker ≠ checker;
 * this only adds the data-shape rule.
 */
class RejectInstallationFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rejectFee', $this->route('customerCharge'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
