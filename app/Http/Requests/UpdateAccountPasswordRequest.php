<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\CurrentPasswordMatches;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * "My account" password change (AccountController::updatePassword()).
 * The current password is always required: a signed-in browser alone is
 * not proof of who is using it.
 */
class UpdateAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', new CurrentPasswordMatches],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
