<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH .../customers/{customer}/disconnect. Reason is implicitly
 * "non-payment" (business-rules.md section 1) — `note` is just an optional
 * free-text addition, never a reason picker like SuspendCustomerRequest's.
 */
class DisconnectCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('disconnect', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
