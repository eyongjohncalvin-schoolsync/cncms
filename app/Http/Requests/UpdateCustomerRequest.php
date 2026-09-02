<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('customer'));
    }

    /**
     * `services` is `sometimes`, matching every other field here — this
     * request already serves several different partial-update callers
     * (the full edit form, a status change, etc.), and
     * CustomerData::$services === null already means "don't touch
     * subscriptions" (services.md section 5). The rebuilt Edit.tsx form
     * always resubmits its full current tick list, so in practice this is
     * present whenever the edit form itself posts.
     *
     * See StoreCustomerRequest's doc comment for why the deeper
     * invariants (duplicates, an option needs its base service, unknown
     * uuids) live in CustomerSubscriptionService::sync() and aren't
     * duplicated here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuid' => ['sometimes', 'required', 'uuid'],
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'location' => ['sometimes', 'nullable', 'string', 'max:30'],
            'bill' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'others' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'description' => ['sometimes', 'nullable', 'string'],
            'level' => ['sometimes', 'nullable', 'string', 'in:normal,Vip,Operator'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,passive,disconnected,suspended'],
            'services' => ['sometimes', 'array', 'min:1'],
            'services.*.service_uuid' => ['required_with:services', 'uuid', 'exists:services,uuid'],
            'services.*.service_variant_uuid' => ['nullable', 'uuid', 'exists:service_variants,uuid'],
            'services.*.price' => ['required_with:services', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
        ];
    }
}
