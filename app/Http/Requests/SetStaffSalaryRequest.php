<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A staff member's salary (branches v2 D8): an amount, usually on one of
 * headquarters' grades, and why. Outside the grade's range it waits for
 * headquarters.
 */
class SetStaffSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('salaries.setFor', $this->route('tenantUser'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'grade_uuid' => ['nullable', 'uuid', Rule::exists('salary_grades', 'uuid')->where('is_active', true)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
