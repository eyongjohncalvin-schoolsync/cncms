<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /installation-charges/{customerCharge}/waiver/reject.
 * App\Policies\CustomerChargePolicy::rejectWaiver() enforces maker ≠ checker;
 * this only adds the data-shape rule.
 */
class RejectInstallationWaiverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rejectWaiver', $this->route('customerCharge'));
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
