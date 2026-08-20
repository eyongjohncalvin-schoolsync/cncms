<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuid' => ['sometimes', 'required', 'uuid'],
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'location' => ['sometimes', 'nullable', 'string', 'max:30'],
            'bill' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2'],
            'others' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'description' => ['sometimes', 'nullable', 'string'],
            'level' => ['sometimes', 'nullable', 'string', 'in:normal,Vip,Operator'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,passive,disconnected,suspended'],
        ];
    }
}
