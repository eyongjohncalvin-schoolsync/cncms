<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

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
        ];
    }
}
