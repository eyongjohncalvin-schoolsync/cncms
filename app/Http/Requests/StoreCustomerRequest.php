<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    /**
     * `bill` and `services` are mutually backward-compatible, not both
     * required (services.md section 6): the rebuilt Create.tsx form sends
     * `services[]` and no `bill`; App\Services\CustomerImportService (xlsx
     * import) still sends only the legacy `bill` column and no `services`
     * — its per-row validation reuses these exact rules
     * (`(new StoreCustomerRequest)->rules()`), so tightening `bill` to
     * `prohibited` here would break every import. Whichever one is sent,
     * App\Services\CustomerSubscriptionService is the single place that
     * turns it into subscription rows and the final `bill` (see
     * CustomerSubscriptionService::defaultSelection()'s $bill parameter).
     *
     * The deeper invariants — no duplicate (service, option) pair, an
     * option requires its base service also selected, unknown uuids — are
     * enforced once, in CustomerSubscriptionService::sync(), not
     * duplicated here; a ValidationException it throws surfaces as a
     * normal 422/redirect-with-errors exactly like a rule failure would.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:30'],
            'bill' => ['required_without:services', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'others' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'phone' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'level' => ['nullable', 'string', 'in:normal,Vip,Operator'],
            'status' => ['nullable', 'string', 'in:active,passive,disconnected,suspended'],
            'services' => ['required_without:bill', 'array', 'min:1'],
            'services.*.service_uuid' => ['required_with:services', 'uuid', 'exists:services,uuid'],
            'services.*.service_variant_uuid' => ['nullable', 'uuid', 'exists:service_variants,uuid'],
            'services.*.price' => ['required_with:services', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
        ];
    }
}
