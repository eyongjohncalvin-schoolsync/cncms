<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InstallationOffer;
use App\Services\FreeMonthGrantService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create / edit an installation offer (Settings → Installation offers):
 * the fee, an optional validity window, active/default, and the free months
 * per service — each capped by companies.max_free_months (D8).
 */
class SaveInstallationOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $offer = $this->route('installationOffer');

        return $offer instanceof InstallationOffer
            ? $this->user()->can('update', $offer)
            : $this->user()->can('create', InstallationOffer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $offer = $this->route('installationOffer');

        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('installation_offers', 'name')->ignore($offer instanceof InstallationOffer ? $offer->id : null)],
            'description' => ['nullable', 'string', 'max:255'],
            'fee' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'active' => ['boolean'],
            'is_default' => ['boolean'], // ignored since 2026-10-26: promotions are never preselected
            'months' => ['nullable', 'array'],
            'months.*.service_uuid' => ['required', 'uuid', 'exists:services,uuid'],
            'months.*.months' => ['required', 'integer', 'min:0', 'max:'.FreeMonthGrantService::maxMonths()],
        ];
    }
}
