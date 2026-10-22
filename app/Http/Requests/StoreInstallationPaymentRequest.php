<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /installation-charges/{customerCharge}/payments — records an
 * installation instalment (D2). The amount is checked against what is still
 * to be paid by App\Services\CustomerChargeService::recordPayment(). Gated by
 * App\Policies\CustomerChargePolicy::recordPayment() (`payments.create`).
 */
class StoreInstallationPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordPayment', $this->route('customerCharge'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            ...Payment::methodRules(),
        ];
    }
}
