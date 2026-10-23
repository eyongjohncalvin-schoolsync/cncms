<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Landlord "Reset 2FA" for a locked-out user (Landlord\UserController::
 * resetTwoFactor()). The route is already behind `landlord` and
 * `not-impersonating`; the reason is required for the activity log.
 */
class ResetTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_landlord;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
