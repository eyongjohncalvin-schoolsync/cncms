<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service sign-up: one combined submit creates the central User AND
 * provisions the new workspace (Tenant) in a single request — see
 * App\Http\Controllers\RegisterController::store() and
 * .ai/skills/cncms/cncms-context/references/self-service-onboarding.md.
 * Reachable by guests only (routes/web/register.php is deliberately outside
 * the ['auth', 'tenant.web'] group), so authorize() is always true — there
 * is no user/tenant yet to check a Policy against.
 */
class StoreRegistrationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:pgsql.users,email'],
            'password' => ['required', 'string', 'min:8'],

            // Same shape as App\Http\Requests\UpdateCompanyRequest, reused
            // deliberately so the registrant's company info seeds the new
            // tenant's Company row via the identical fields Settings >
            // Company Info later edits.
            'company_name' => ['required', 'string', 'max:50'],
            'company_location' => ['required', 'string', 'max:30'],
            'company_phone' => ['required', 'string', 'max:30'],
            'momo_number' => ['nullable', 'string', 'max:30'],
            'momo_name' => ['nullable', 'string', 'max:50'],

            // Becomes the tenant's `id` (its Postgres schema name is
            // derived from it — see config/tenancy.php's 'prefix'), same
            // rule shape as App\Http\Requests\StoreTenantRequest's 'slug'.
            'workspace_slug' => ['required', 'string', 'max:63', 'alpha_dash', 'unique:tenants,id'],
        ];
    }
}
