<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DataTransferObjects\OneOffChargeData;
use App\Models\CustomerCharge;
use App\Models\Payment;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /customers/{customer}/reinstallation — a re-installation fee at the
 * same address (docs/plans/installation-and-promotions.md, "Out of scope /
 * later"). Gated by App\Policies\CustomerChargePolicy::reinstall()
 * (`installations.manage`); taking money now also needs `payments.create`.
 * The fee rules (default, lower fee / waiver → reason + approval) are
 * App\Services\CustomerRelocationService's.
 */
class ReinstallCustomerRequest extends FormRequest
{
    /** The CustomerChargePolicy ability this form needs. */
    protected string $ability = 'reinstall';

    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $this->user()->can($this->ability, CustomerCharge::class)
            && $this->user()->can('view', $customer)
            && ! $customer->trashed()
            && ((float) $this->input('paid_now', 0) <= 0 || app(TenantContext::class)->can('payments.create'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'paid_now' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
            ...Payment::methodRules(),
        ];
    }

    public function toData(): OneOffChargeData
    {
        return OneOffChargeData::fromArray($this->validated());
    }
}
