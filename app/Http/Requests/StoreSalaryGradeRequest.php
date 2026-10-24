<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** A step of headquarters' salary scale (branches v2 D8): name and range. */
class StoreSalaryGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('salaries.manageScale');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('salary_grades', 'name')->ignore($this->route('salaryGrade'))],
            'min_amount' => ['required', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
            'max_amount' => ['required', 'numeric', 'gte:min_amount', 'max:999999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['min_amount' => 'minimum', 'max_amount' => 'maximum'];
    }
}
