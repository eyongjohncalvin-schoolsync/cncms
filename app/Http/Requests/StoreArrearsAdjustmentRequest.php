<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ArrearsAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * POST /arrears-adjustments. Deliberately ungated at the FormRequest level
 * beyond "is an authenticated tenant user" — ArrearsAdjustmentPolicy::create()
 * is unconditionally true for all five roles, matching
 * App\Http\Requests\StoreComplaintRequest's identical "no gate" convention
 * for the one other feature in this app every role may use.
 *
 * `reason_note` is required non-empty even though the underlying column is
 * a plain `text` (never null in practice, but nullable at the schema level)
 * — same "required-even-though-nullable-column" pattern as
 * ResolveComplaintRequest's resolution_notes.
 */
class StoreArrearsAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ArrearsAdjustment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_uuid' => ['required', 'uuid'],
            // Cannot be in the future — an adjustment corrects a period
            // that has already been billed (or is being billed right now),
            // never one that hasn't happened yet.
            'target_period' => [
                'required',
                'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
                        return;
                    }

                    if ($value > Carbon::now()->format('Y-m')) {
                        $fail('The target period cannot be in the future.');
                    }
                },
            ],
            'direction' => ['required', Rule::in(['decrease', 'increase'])],
            // Which side of `net = arrears - credit` this correction lands on.
            // Optional/defaulted so every existing caller (and the JSON API)
            // stays valid without sending it — see ArrearsAdjustmentData.
            'target' => ['nullable', Rule::in(['arrears', 'credit'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            // Original arrears categories PLUS the credit-correction set
            // (2026-08-30) — the column has no DB CHECK constraint, this is
            // the single enforcement point for the allowed values.
            'reason_category' => ['required', Rule::in([
                'legacy_migration_error', 'billing_error', 'goodwill_service_outage',
                'bad_debt_writeoff', 'credit_clawback', 'other',
                'credit_correction', 'duplicate_credit', 'migration_credit_error',
            ])],
            'reason_note' => ['required', 'string'],
            'complaint_uuid' => ['nullable', 'uuid'],
        ];
    }
}
