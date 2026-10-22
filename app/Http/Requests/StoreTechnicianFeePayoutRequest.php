<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cash/technician-fees/payouts — the cashier pays collected technician
 * motivation fees to a named technician from their till (D13,
 * docs/plans/installation-and-promotions.md). Same gate as receiving
 * handovers into a till: `cash.receive`.
 */
class StoreTechnicianFeePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cash.receive');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*' => ['required', 'uuid'],
            'technician_name' => ['required', 'string', 'min:2', 'max:150'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
