<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CustomerCharge;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /customers/{customer}/installation-charges — puts an installation
 * fee on a customer (docs/plans/installation-and-promotions.md, phase 1).
 * Gated by App\Policies\CustomerChargePolicy::create() (`installations.manage`).
 */
class StoreCustomerChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CustomerCharge::class)
            && $this->user()->can('view', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
