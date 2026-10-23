<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /free-months/{freeMonthGrant}/reject — App\Policies\FreeMonthGrantPolicy
 * enforces maker ≠ checker; this only adds the data-shape rule.
 */
class RejectFreeMonthGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', $this->route('freeMonthGrant'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
