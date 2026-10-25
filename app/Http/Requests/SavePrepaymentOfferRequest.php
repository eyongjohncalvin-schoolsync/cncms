<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PrepaymentOffer;
use Illuminate\Foundation\Http\FormRequest;

/** Adding or editing a prepayment offer (Settings → Prepayment offers). */
class SavePrepaymentOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $offer = $this->route('prepaymentOffer');

        return $offer instanceof PrepaymentOffer
            ? $this->user()->can('update', $offer)
            : $this->user()->can('create', PrepaymentOffer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'months' => ['required', 'integer', 'min:2', 'max:36'],
            'price' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'active' => ['required', 'boolean'],
        ];
    }
}
