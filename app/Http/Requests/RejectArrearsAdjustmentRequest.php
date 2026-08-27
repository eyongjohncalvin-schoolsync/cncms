<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /arrears-adjustments/{arrearsAdjustment}/reject.
 * App\Policies\ArrearsAdjustmentPolicy::reject() enforces the state-dependent
 * role/identity gate (mirrors ::approve() exactly); this class only adds the
 * data-shape rule: `rejection_reason` is required non-empty.
 */
class RejectArrearsAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', $this->route('arrearsAdjustment'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string'],
        ];
    }
}
