<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approves many pending payments in one request — see
 * App\Services\PaymentVerificationService::verifyMany(). Deliberately
 * approve-only: a bulk reject would need a shared reason across
 * dissimilar payments, which isn't a real workflow (business-rules.md's
 * reject path is always a per-payment judgment call), so the single-payment
 * VerifyPaymentRequest stays the only way to reject.
 */
class BulkVerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bulkVerify', Payment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_uuids' => ['required', 'array', 'min:1', 'max:200'],
            'payment_uuids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
