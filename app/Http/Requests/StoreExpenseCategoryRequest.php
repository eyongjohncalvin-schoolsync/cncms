<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ExpenseCategory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:30'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
