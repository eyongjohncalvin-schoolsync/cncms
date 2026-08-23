<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('verify', $this->route('payment'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject'],
            'momo_ref' => ['nullable', 'string', 'max:50'],
            'notes' => ['required_if:action,reject', 'nullable', 'string', 'max:1000'],
        ];
    }
}
