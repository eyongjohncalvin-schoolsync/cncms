<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\BranchPriceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A branch's own price for a service, option or installation offer
 * (branches v2 D4). An empty price hands it back to headquarters' price; a
 * reason is always required — it is the price history.
 */
class SetBranchPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', $this->route('branch'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::in(BranchPriceService::TARGETS)],
            'target_uuid' => ['required', 'uuid'],
            'price' => ['nullable', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:255'],
            'apply_to_subscribers' => ['boolean'],
        ];
    }
}
