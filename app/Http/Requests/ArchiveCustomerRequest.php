<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH .../customers/{customer}/archive (customer-deletion deliberation,
 * 2026-08-29). Body: {name, reason}.
 *
 * `name` must match the customer's own name exactly — the type-to-confirm
 * gate the danger modal enforces on the client, re-checked here so the
 * endpoint can't be driven without it. `reason` is a required permanent
 * audit note (stored on `customers.archived_reason` and in the audit row).
 *
 * The archive is deliberately NOT blocked on pending arrears adjustments,
 * unverified payments, or a live prepaid window — the modal warns about
 * those, it does not gate on them (blocking would recreate the hard-block
 * this feature exists to remove).
 */
class ArchiveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('archive', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', Rule::in([$this->route('customer')->name])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.in' => 'The name you typed does not match this customer exactly.',
        ];
    }
}
