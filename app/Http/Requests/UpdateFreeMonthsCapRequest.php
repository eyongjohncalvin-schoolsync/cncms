<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InstallationOffer;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
