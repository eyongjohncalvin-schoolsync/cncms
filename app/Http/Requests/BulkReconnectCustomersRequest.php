<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reconnects many customers in one request from the Disconnections page —
 * see App\Services\CustomerStatusService::reconnectMany(). `include_fine`
 * (2026-08 owner decision, business-rules.md section 6) is a plain optional
 * boolean, unchecked by default — the reconnection fine is admin-discretion
 * opt-in for every selected customer regardless of whether they're
 * currently `disconnected` or `suspended`, never required/`accepted` the
 * way the old `fine_collected` confirmation field was.
 */
class BulkReconnectCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bulkReconnect', Customer::class);
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
            'include_fine' => ['sometimes', 'boolean'],
        ];
    }
}
