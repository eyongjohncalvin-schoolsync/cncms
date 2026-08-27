<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expenditure;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenditureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Expenditure::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'description' => ['nullable', 'string'],
            'spent_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'recorded_offline' => ['nullable', 'boolean'],
            'recorded_by_device' => ['nullable', 'string', 'max:255'],
        ];
    }
}
