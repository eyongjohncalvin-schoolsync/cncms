<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('agent'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuid' => ['sometimes', 'required', 'uuid'],
            'user_uuid' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'location' => ['sometimes', 'required', 'string', 'max:50'],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'salary' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2'],
            'email' => ['sometimes', 'nullable', 'email', 'max:50'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'in:yes,no'],
            'children' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,inactive'],
            'picture' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
