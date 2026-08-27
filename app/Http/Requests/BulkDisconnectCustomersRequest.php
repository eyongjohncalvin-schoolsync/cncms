<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Disconnects many customers in one request from the Disconnections page —
 * see App\Services\CustomerStatusService::disconnectMany(). `note`, if
 * given, is shared across the whole selected batch.
 */
class BulkDisconnectCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bulkDisconnect', Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_uuids' => ['required', 'array', 'min:1', 'max:200'],
            'customer_uuids.*' => ['required', 'uuid', 'distinct'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
