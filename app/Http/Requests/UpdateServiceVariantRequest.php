<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Service;
use App\Models\ServiceVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class UpdateServiceVariantRequest extends FormRequest
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
        /** @var ServiceVariant $variant */
        $variant = $this->route('variant');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:80',
                function (string $attribute, mixed $value, \Closure $fail) use ($service, $variant): void {
                    $exists = DB::table('service_variants')
                        ->where('service_id', $service->id)
                        ->where('id', '!=', $variant->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail("\"{$service->name}\" already has an option with this name.");
                    }
                },
            ],
            'price' => ['sometimes', 'required', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
