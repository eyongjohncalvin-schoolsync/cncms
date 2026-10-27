<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Service::class);
    }

    /**
     * A plain `unique:services,name` rule is case-SENSITIVE — the DB's own
     * guard (`uq_services_name_ci`, services.md section 3) is
     * case-insensitive, so "TV Service" vs "tv service" must be caught
     * here too or it surfaces as an ugly 500 from the constraint instead of
     * a friendly 422.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:60',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $exists = DB::table('services')->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])->exists();

                    if ($exists) {
                        $fail('A service with this name already exists.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
            'is_default' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            ...self::priceOptionRules(),
        ];
    }

    /**
     * Price options and the minimum price (owner, 2026-10-27) — shared with
     * UpdateServiceRequest.
     *
     * @return array<string, mixed>
     */
    public static function priceOptionRules(): array
    {
        return [
            'price_options' => ['sometimes', 'nullable', 'array', 'max:10'],
            'price_options.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'price_options.*.label' => ['nullable', 'string', 'max:40'],
            'min_price' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * No default price or price option below the minimum — staff could
     * otherwise pick a price the minimum is meant to forbid.
     *
     * @param  array<int, array{amount?: mixed}>|null  $options
     */
    public static function checkMinimum(Validator $validator, mixed $price, mixed $minimum, ?array $options): void
    {
        if ($minimum === null || $minimum === '') {
            return;
        }

        if ($price !== null && $price !== '' && (float) $price > 0 && (float) $price < (float) $minimum) {
            $validator->errors()->add('min_price', 'The minimum cannot be above the default price.');
        }

        foreach ($options ?? [] as $option) {
            if (isset($option['amount']) && (float) $option['amount'] < (float) $minimum) {
                $validator->errors()->add('price_options', 'Every price option must be at least the minimum price.');

                return;
            }
        }
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => self::checkMinimum($validator, $this->input('price'), $this->input('min_price'), $this->input('price_options')),
        ];
    }
}
