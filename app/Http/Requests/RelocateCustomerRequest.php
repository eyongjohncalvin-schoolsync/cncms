<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * POST /customers/{customer}/relocation — the customer moved house: a
 * relocation fee plus the new address (and zone, resolved through the same
 * branch-fenced lookup as a customer edit). Gated by
 * App\Policies\CustomerChargePolicy::relocate() (`installations.manage` +
 * `customers.update`). Same fee and payment fields as a re-installation.
 */
class RelocateCustomerRequest extends ReinstallCustomerRequest
{
    protected string $ability = 'relocate';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'location' => ['required', 'string', 'max:30'],
            'zone_uuid' => ['nullable', 'uuid'],
        ];
    }
}
