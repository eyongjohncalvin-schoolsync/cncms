<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The add-customer form's live preview of its "Installation & first
 * payment" section (docs/plans/installation-and-promotions.md, phase 2) —
 * the same fields as StoreCustomerRequest, all optional, nothing written.
 */
class PreviewCustomerInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'services' => ['nullable', 'array'],
            'services.*.service_uuid' => ['required', 'uuid'],
            'services.*.service_variant_uuid' => ['nullable', 'uuid'],
            'services.*.price' => ['required', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
            ...StoreCustomerRequest::installationRules(),
        ];
    }
}
