<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;

class StoreAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Agent::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuid' => ['required', 'uuid'],
            'user_uuid' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:50'],
            'location' => ['required', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:20'],
            'salary' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'email' => ['nullable', 'email', 'max:50'],
            'dob' => ['nullable', 'date'],
            'marital_status' => ['nullable', 'string', 'in:yes,no'],
            'children' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'picture' => ['nullable', 'string', 'max:255'],
        ];
    }
}
