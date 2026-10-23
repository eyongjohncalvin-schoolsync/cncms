<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Forgot password?" step 1 (web PasswordResetController / API
 * Api\PasswordResetController): `identifier` is a username or an email.
 */
class ForgotPasswordRequest extends FormRequest
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
