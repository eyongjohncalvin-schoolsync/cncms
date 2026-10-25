<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\UniqueUpperCaseLabel;
use App\Support\UpperCaseLabel;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('branch'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:50',
                new UniqueUpperCaseLabel('branches', ignore: $this->route('branch')),
            ],
        ];
    }

    /** See StoreBranchRequest::prepareForValidation(). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => UpperCaseLabel::normalise($this->input('name'))]);
        }
    }
}
