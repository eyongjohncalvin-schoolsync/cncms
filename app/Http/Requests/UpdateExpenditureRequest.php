<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Re-categorise / fix the wording of an expenditure (E7 of
 * docs/plans/expenditures-and-spending-controls.md): admin or owner, never
 * the requester, with a reason, in an open month. The amount, date and
 * source are never edited — a wrong amount is corrected on the bon.
 */
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
            'category_uuid' => ['sometimes', 'required', 'uuid', Rule::exists('expense_categories', 'uuid')],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
