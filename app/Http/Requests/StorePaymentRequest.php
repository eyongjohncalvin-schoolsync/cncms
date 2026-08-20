<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Payment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'credit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'frequency' => ['required', 'string', 'in:monthly,yearly,months'],
            'months' => ['required_if:frequency,months', 'nullable', 'integer', 'min:1'],
            'recorded_offline' => ['nullable', 'boolean'],
            'recorded_by_device' => ['nullable', 'string', 'max:255'],
        ];
    }
}
