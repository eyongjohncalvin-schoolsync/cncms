<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /arrears-adjustments/{arrearsAdjustment}/approve. No body — every
 * business rule (who may approve at the current stage, the two-approver
 * gate, the staleness re-check) is enforced by
 * App\Policies\ArrearsAdjustmentPolicy::approve() and
 * App\Services\ArrearsAdjustmentService::approve() respectively, not by
 * request data.
 */
class ApproveArrearsAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('approve', $this->route('arrearsAdjustment'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
