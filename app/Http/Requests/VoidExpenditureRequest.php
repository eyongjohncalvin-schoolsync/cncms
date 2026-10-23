<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Void a legacy expenditure with a reason — replaces the old hard delete
 * (docs/plans/expenditures-and-spending-controls.md, item 9).
 */
class VoidExpenditureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('void', $this->route('expenditure'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
