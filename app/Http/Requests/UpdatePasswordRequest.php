<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * PATCH /api/v1/auth/password — self-service password change
 * (App\Http\Controllers\Api\AuthController::updatePassword()).
 *
 * `current_password` correctness is verified in the controller via
 * Hash::check() against the existing hash, not here — a FormRequest has no
 * clean way to do that check without duplicating Hash::check() logic that
 * belongs next to the rest of the update, and AuthController::login()
 * already establishes the convention of doing password verification in the
 * controller, not the Request.
 */
class UpdatePasswordRequest extends FormRequest
{
    /** See UpdateProfileRequest::authorize()'s doc comment — same reasoning. */
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
            'current_password' => ['required', 'string'],
            // This codebase's existing password-strength floor elsewhere
            // (StoreTenantUserRequest, StoreRegistrationRequest) is a plain
            // 'min:8', for accounts an admin creates on someone else's
            // behalf. A self-service change is the one path where the
            // person choosing the password is the same person who'll rely
            // on it resisting a guess, so this additionally requires at
            // least one letter and one number via Laravel's built-in
            // Password rule object. `uncompromised()` is deliberately NOT
            // added — it calls the external HaveIBeenPwned API, a real
            // network dependency this app's test suite and offline-capable
            // field context shouldn't take on, and out of proportion for a
            // ~6-user internal tool at this app's current scale.
            'new_password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }
}
