<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/auth/profile — self-service update of the authenticated
 * user's own name/username/email
 * (App\Http\Controllers\Api\AuthController::updateProfile()).
 *
 * Deliberately narrow: `status`/`password`/`locale`/anything else on the
 * central `users` table is not defined here, so it can never surface via
 * $request->validated() no matter what the caller sends — `status` stays an
 * office-only admin action (SettingsUserController::deactivate()), and
 * password has its own endpoint (UpdatePasswordRequest) requiring proof of
 * the current password first.
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * Any authenticated user may edit their own record — the controller
     * resolves strictly from $this->user() (no route parameter exists on
     * this endpoint at all), so there is no "other user's data" to protect
     * against and no Policy check is needed, mirroring how
     * AuthController::me()/logout() already work with no separate
     * authorize() check.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            // 'sometimes': a caller may patch just one of these fields (the
            // mobile "Edit profile" form always sends all three today, but
            // there's no reason to force that at the validation layer —
            // matches UpdateTenantUserRequest's own "each control patches
            // its own field(s)" convention elsewhere in this codebase).
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('pgsql.users', 'username')->ignore($userId),
            ],
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('pgsql.users', 'email')->ignore($userId),
            ],
        ];
    }
}
