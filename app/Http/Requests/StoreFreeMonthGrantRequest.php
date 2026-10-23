<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FreeMonthGrant;
use App\Services\FreeMonthGrantService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /customers/{customer}/free-months — compensation free months for
 * poor service (D12): every service or the ones ticked, up to the company's
 * cap per grant, always with a reason, optionally alongside an arrears
 * write-off request (the existing maker-checker arrears adjustment).
 */
class StoreFreeMonthGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FreeMonthGrant::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_uuids' => ['nullable', 'array'],
            'service_uuids.*' => ['uuid', 'exists:services,uuid'],
            'months' => ['required', 'integer', 'min:1', 'max:'.FreeMonthGrantService::maxMonths()],
            'reason' => ['required', 'string', 'max:500'],
            'write_off_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('write_off_amount') && ! app(TenantContext::class)->can('arrears.request')) {
                $validator->errors()->add('write_off_amount', 'You may not request an arrears write-off.');
            }
        }];
    }
}
