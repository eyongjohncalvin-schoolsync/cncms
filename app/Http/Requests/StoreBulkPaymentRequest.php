<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One payment per selected customer, each recorded at that customer's own
 * `bill` — see App\Services\PaymentService::createBulk(). Amount is
 * deliberately not accepted here (unlike StorePaymentRequest): the whole
 * point of bulk entry is "this customer paid exactly their standard
 * monthly bill", so there's nothing for the caller to type per row.
 */
class StoreBulkPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bulkCreate', Payment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_uuids' => ['required', 'array', 'min:1', 'max:200'],
            'customer_uuids.*' => ['required', 'uuid', 'distinct'],
            // Each customer pays exactly one monthly bill; a prepayment's
            // amount differs per customer, so it goes through the single form.
            'frequency' => ['required', 'string', 'in:monthly'],
            'months' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'frequency.in' => 'Bulk entry is for monthly payments only — record a prepayment on its own.',
        ];
    }
}
