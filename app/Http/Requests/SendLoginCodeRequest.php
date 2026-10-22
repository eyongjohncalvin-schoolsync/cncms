<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Email me a sign-in code" (web LoginCodeController / API
 * Api\LoginCodeController): `identifier` is a username or an email.
 */
class SendLoginCodeRequest extends FormRequest
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
        ];
    }
}
