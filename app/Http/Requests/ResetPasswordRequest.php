<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * "Forgot password?" step 2 (web PasswordResetController / API
 * Api\PasswordResetController): the emailed code plus the new password.
 * The password rules run before the code is checked, so a too-weak
 * password never spends the code.
 */
class ResetPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
