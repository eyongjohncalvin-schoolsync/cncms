<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Adding an "option" (services.md section 4 — e.g. a TV channel) under the
 * route-bound {service}. Authorized against ServicePolicy::update() on the
 * PARENT service, not a dedicated variant policy — see ServicePolicy's
 * class doc.
 */
class StoreServiceVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('service'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Service $service */
        $service = $this->route('service');

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                function (string $attribute, mixed $value, \Closure $fail) use ($service): void {
                    $exists = DB::table('service_variants')
                        ->where('service_id', $service->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail("\"{$service->name}\" already has an option with this name.");
                    }
                },
            ],
            'price' => ['required', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
