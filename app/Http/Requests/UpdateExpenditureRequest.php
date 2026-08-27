<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenditureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('expenditure'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_uuid' => ['sometimes', 'required', 'uuid'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'description' => ['sometimes', 'nullable', 'string'],
            'spent_at' => ['sometimes', 'required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'recorded_offline' => ['sometimes', 'nullable', 'boolean'],
            'recorded_by_device' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
