<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Branch;
use App\Rules\UniqueUpperCaseLabel;
use App\Support\UpperCaseLabel;
use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Branch::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', new UniqueUpperCaseLabel('branches')],
        ];
    }

    /**
     * Normalised before validation so max:50 and the unique check judge the
     * value that will be stored ("buea" is rejected when "BUEA" exists).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => UpperCaseLabel::normalise($this->input('name'))]);
        }
    }
}
