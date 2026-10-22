<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /installation-charges/{customerCharge}/waiver — asks for what is left
 * of an installation fee to be waived. Someone else approves it (D8).
 * Gated by App\Policies\CustomerChargePolicy::requestWaiver().
 */
class RequestInstallationWaiverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('requestWaiver', $this->route('customerCharge'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
