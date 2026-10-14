<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OpeningBalanceBatch;
use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningBalanceBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('openingBalanceBatch');

        return $batch instanceof OpeningBalanceBatch
            ? $this->user()->can('update', $batch)
            : $this->user()->can('create', OpeningBalanceBatch::class);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'go_live_period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'go_live_period.regex' => 'The first billing month must be in YYYY-MM format.',
        ];
    }
}
