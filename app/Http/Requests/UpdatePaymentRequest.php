<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('payment'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,2'],
            'credit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'frequency' => ['sometimes', 'required', 'string', 'in:monthly,yearly,months'],
            'months' => ['required_if:frequency,months', 'nullable', 'integer', 'min:1'],
            'recorded_offline' => ['sometimes', 'nullable', 'boolean'],
            'recorded_by_device' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
