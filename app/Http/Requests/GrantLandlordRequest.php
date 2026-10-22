<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Landlord-only (App\Http\Controllers\Landlord\LandlordController::store).
 * See StoreTenantRequest for why authorize() returns true: the `landlord`
 * middleware gates every route in routes/web/landlord.php. Only an existing
 * user can be granted; accounts are created by registering or by
 * `cncms:grant-landlord --create`.
 */
class GrantLandlordRequest extends FormRequest
{
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
            'email' => ['required', 'email', 'exists:pgsql.users,email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.exists' => 'No user has this email address. They need an account first.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }
}
