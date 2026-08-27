<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Backs both POST /customers/bulk-update-bill (commit) and POST
 * /customers/bulk-update-bill/preview (dry run, no writes) — see
 * App\Services\CustomerService::bulkUpdateBill()/previewBulkBillUpdate(),
 * which share the exact same request shape and the exact same bcmath
 * computation, so this one FormRequest backs both actions rather than being
 * duplicated per endpoint.
 *
 * Selection is either an explicit `customer_uuids` list OR a filter
 * descriptor (`zone_uuid`/`level`/`status`/`search` — the exact same shape
 * CustomerController::index() already accepts), so a large "everyone
 * matching this filter" batch doesn't require serializing hundreds of
 * uuids into the request. At least one of the two must actually narrow the
 * selection — see withValidator() below.
 *
 * Same policy gate as the single-customer edit form
 * (CustomerPolicy::update() — super/admin/manager): a bulk price
 * adjustment is not a distinct ability, it is the same "can edit customer
 * billing" permission applied to many rows at once.
 */
class BulkUpdateCustomerBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_uuids' => ['sometimes', 'array', 'max:2000'],
            'customer_uuids.*' => ['required', 'uuid', 'distinct'],
            'zone_uuid' => ['sometimes', 'nullable', 'uuid'],
            'level' => ['sometimes', 'nullable', 'string', 'in:normal,Vip,Operator'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,passive,disconnected,suspended'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mode' => ['required', 'string', 'in:set,increase_fixed,increase_percent'],
            // Deliberately NOT gt:0 here — increase_fixed/increase_percent
            // both accept a negative value to express a decrease (e.g. a
            // cost-of-living rollback). Whether the RESULTING bill is valid
            // per customer (positive, within the max) is checked server-side
            // per row by App\Services\CustomerService::assertValidBill(),
            // never trusted from the client.
            'value' => ['required', 'numeric', 'between:-999999999.99,999999999.99', 'decimal:0,2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasUuids = ! empty($this->input('customer_uuids'));
            $hasFilter = $this->filled('zone_uuid') || $this->filled('level') || $this->filled('status') || $this->filled('search');

            if (! $hasUuids && ! $hasFilter) {
                $validator->errors()->add(
                    'customer_uuids',
                    'Select customers explicitly or provide at least one filter (zone, level, status, or search).',
                );
            }
        });
    }
}
