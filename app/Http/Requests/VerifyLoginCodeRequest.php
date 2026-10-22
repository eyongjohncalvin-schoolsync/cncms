<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Signing in with an emailed code (web LoginCodeController / API
 * Api\LoginCodeController).
 */
class VerifyLoginCodeRequest extends FormRequest
{
    /** Guests by definition. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
