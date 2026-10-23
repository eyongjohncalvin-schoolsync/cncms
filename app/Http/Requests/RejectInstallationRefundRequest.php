<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /installation-charges/{customerCharge}/refund/reject.
 * App\Policies\CustomerChargePolicy::rejectRefund() enforces maker ≠ checker;
 * this only adds the data-shape rule.
 */
class RejectInstallationRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rejectRefund', $this->route('customerCharge'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
