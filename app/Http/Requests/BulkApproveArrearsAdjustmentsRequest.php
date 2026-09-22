<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Class-level gate only — whether the actor may approve EACH selected row
 * (maker≠checker, second-approval stage) is re-checked per row by
 * ArrearsAdjustmentService::approveMany(), which skips rather than fails.
 */
class BulkApproveArrearsAdjustmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('arrears.approve');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'adjustment_uuids' => ['required', 'array', 'min:1', 'max:100'],
            'adjustment_uuids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
