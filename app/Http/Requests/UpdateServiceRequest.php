<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('service'));
    }

    /**
     * See StoreServiceRequest's doc comment for why `name` uniqueness is a
     * closure rather than a plain `unique` rule — same case-insensitive
     * reasoning, excluding this service's own row.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Service $service */
        $service = $this->route('service');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:60',
                function (string $attribute, mixed $value, \Closure $fail) use ($service): void {
                    $exists = DB::table('services')
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->where('id', '!=', $service->id)
                        ->exists();

                    if ($exists) {
                        $fail('A service with this name already exists.');
                    }
                },
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
            'is_default' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            ...StoreServiceRequest::priceOptionRules(),
        ];
    }

    /**
     * The same minimum check as StoreServiceRequest, against this
     * service's values for any field the request leaves out.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        /** @var Service $service */
        $service = $this->route('service');

        return [
            fn (Validator $validator) => StoreServiceRequest::checkMinimum(
                $validator,
                $this->has('price') ? $this->input('price') : $service->price,
                $this->has('min_price') ? $this->input('min_price') : $service->min_price,
                $this->has('price_options') ? $this->input('price_options') : $service->price_options,
            ),
        ];
    }
}
