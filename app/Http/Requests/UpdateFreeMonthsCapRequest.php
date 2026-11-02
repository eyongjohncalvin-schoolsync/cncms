<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Auth\Permission;
use App\Models\InstallationOffer;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The company's cap on free months per grant (D8: default 3), set on
 * Settings → Installation offers.
 */
class UpdateFreeMonthsCapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InstallationOffer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'max_free_months' => ['required', 'integer', 'min:1', 'max:12'],
            // Free months one customer may be given a year without approval
            // (0 = always ask) — how much compensation skips approval, so it
            // is the approvers' setting (2026-11-02).
            'compensation_months_without_approval' => [
                'sometimes', 'integer', 'min:0', 'max:12',
                Rule::prohibitedIf(fn (): bool => ! app(TenantContext::class)->can(Permission::CompensationApprove->value)),
            ],
        ];
    }
}
