<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InstallationOffer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The company's default relocation and re-installation fees, set on
 * Settings → Installation offers (empty = no default: the office types the
 * fee each time). Same gate as the offers and the free-month cap.
 */
class UpdateOneOffFeesRequest extends FormRequest
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
            'relocation_fee' => ['nullable', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reinstallation_fee' => ['nullable', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }
}
