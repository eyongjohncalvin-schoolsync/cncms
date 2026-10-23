<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /installation-charges/{customerCharge}/refund — "Refund installation"
 * (D9). The installation fee is non-refundable by default, so a refund is
 * asked for with a reason and approved by someone else.
 */
class RequestInstallationRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('requestRefund', $this->route('customerCharge'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
