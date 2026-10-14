<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expenditure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Settings → expense approval and evidence limits.
 */
class UpdateExpenseLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageResourcesSettings', Expenditure::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expense_auto_approve_below' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'expense_second_approval_above' => ['required', 'numeric', 'gte:expense_auto_approve_below', 'max:999999999'],
            'expense_receipt_required_from' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'expense_supplier_required_from' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'expense_backdate_days' => ['required', 'integer', 'min:0', 'max:31'],
        ];
    }
}
