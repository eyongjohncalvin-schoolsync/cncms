<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /field-installations/{fieldInstallation}/activate — what the office
 * confirms or corrects on a field registration: zone, offer, connection
 * date and free months per service (installation phase 3). Anything left
 * out stands as the agent proposed it. The months paid upfront are money
 * already taken and cannot be changed here.
 */
class ActivateFieldInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('activate', $this->route('fieldInstallation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuid' => ['nullable', 'uuid', 'exists:zones,uuid'],
            'installation_offer_uuid' => ['nullable', 'uuid'],
            'connected_on' => ['nullable', 'date_format:Y-m-d'],
            'service_plans' => ['nullable', 'array'],
            'service_plans.*.service_uuid' => ['required', 'uuid'],
            'service_plans.*.free_from' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'service_plans.*.free_months' => ['nullable', 'integer', 'min:0', 'max:36'],
            'installation_reason' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
