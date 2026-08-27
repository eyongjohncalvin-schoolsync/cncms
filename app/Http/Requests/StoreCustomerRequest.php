<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:30'],
            'bill' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'others' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'phone' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'level' => ['nullable', 'string', 'in:normal,Vip,Operator'],
            'status' => ['nullable', 'string', 'in:active,passive,disconnected,suspended'],
        ];
    }
}
