<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expenditure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reopen a closed expenditure month — the owner only, with a reason (E6 of
 * docs/plans/expenditures-and-spending-controls.md).
 */
class ReopenExpenditureMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageMonths', Expenditure::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
