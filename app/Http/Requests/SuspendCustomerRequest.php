<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH .../customers/{customer}/suspend. `reason` must be one of the fixed
 * set the office picks from; `other` additionally requires a free-text
 * `note` since it isn't self-explanatory the way the other three are.
 *
 * `pause_prepaid` (references/prepaid-pause-handling.md section 5) is the
 * admin's choice, shown by the suspend modal ONLY when the customer has an
 * active/unexpired prepaid window — "Pause the prepaid countdown"
 * (pre-selected/Recommended) vs. "Let it continue as normal". Plain
 * optional boolean defaulting to true (App\Services\CustomerStatusService::
 * suspend()'s own `$pausePrepaid` default) so a caller that omits it
 * entirely (or a customer with nothing to choose between, where the field
 * is simply moot) still gets the recommended/safe behavior.
 */
class SuspendCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('suspend', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'in:tv_problem,poor_service,customer_request,zone_transfer,other'],
            'note' => ['required_if:reason,other', 'nullable', 'string', 'max:1000'],
            'pause_prepaid' => ['sometimes', 'boolean'],
        ];
    }
}
