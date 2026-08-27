<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillPrintingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Company::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bill_template' => ['required', 'string', Rule::in(Company::BILL_TEMPLATES)],
            'bills_per_page' => ['required', 'integer', Rule::in(Company::BILLS_PER_PAGE_OPTIONS)],
        ];
    }
}
