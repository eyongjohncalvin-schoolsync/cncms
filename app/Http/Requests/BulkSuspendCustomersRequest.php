<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspends many customers in one request from the Disconnections page —
 * see App\Services\CustomerStatusService::suspendMany(). One `reason` (and
 * `note`, if 'other') applies to the whole selected batch.
 */
class BulkSuspendCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bulkSuspend', Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_uuids' => ['required', 'array', 'min:1', 'max:200'],
            'customer_uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'in:tv_problem,poor_service,customer_request,zone_transfer,other'],
            'note' => ['required_if:reason,other', 'nullable', 'string', 'max:1000'],
        ];
    }
}
