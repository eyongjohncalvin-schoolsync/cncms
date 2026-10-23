<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correct a paid bon (docs/plans/expenditures-and-spending-controls.md,
 * item 9): money returned, or the payee underpaid. Admin or owner, never
 * the requester (VoucherPolicy::correct), always with a reason.
 */
class CorrectVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('correct', $this->route('voucher'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['returned', 'underpaid'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
