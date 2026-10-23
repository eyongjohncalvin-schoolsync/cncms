<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expenditure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One month's per-category budgets from Resources → Budgets (E5 of
 * docs/plans/expenditures-and-spending-controls.md). A blank or zero amount
 * removes that category's budget.
 */
class UpdateBudgetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageBudgets', Expenditure::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'amounts' => ['present', 'array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
        ];
    }
}
