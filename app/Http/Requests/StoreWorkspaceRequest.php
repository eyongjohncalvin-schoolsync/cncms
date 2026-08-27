<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Company-info-ONLY workspace creation for an already-authenticated user
 * with no tenant yet (typically arrived via Google OAuth — see
 * App\Http\Controllers\GoogleAuthController::callback()). No name/email/
 * password fields: the User already exists. See
 * App\Http\Controllers\RegisterController::storeWorkspace() and
 * .ai/skills/cncms/cncms-context/references/self-service-onboarding.md.
 * authorize() just checks the requester is logged in — the route itself
 * sits behind the `auth` middleware (not `tenant.web`, since they don't
 * have a tenant yet, that's the point), so this is a belt-and-braces check
 * rather than the sole gate.
 */
class StoreWorkspaceRequest extends FormRequest
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
            'company_name' => ['required', 'string', 'max:50'],
            'company_location' => ['required', 'string', 'max:30'],
            'company_phone' => ['required', 'string', 'max:30'],
            'momo_number' => ['nullable', 'string', 'max:30'],
            'momo_name' => ['nullable', 'string', 'max:50'],
            'workspace_slug' => ['required', 'string', 'max:63', 'alpha_dash', 'unique:tenants,id'],
        ];
    }
}
