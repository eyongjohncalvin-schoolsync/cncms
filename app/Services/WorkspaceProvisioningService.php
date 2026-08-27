<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;

/**
 * Provisions a brand-new self-service workspace (Tenant) end to end,
 * following the exact pattern proven by database/seeders/DatabaseSeeder.php
 * and App\Http\Controllers\Landlord\TenantController::store() — with two
 * differences specific to the self-service path (see
 * .ai/skills/cncms/cncms-context/references/self-service-onboarding.md):
 *
 *   1. `registration_status` is set to 'pending' (not 'approved' — a public
 *      signup is untrusted until a landlord reviews it), gating dashboard
 *      access via ResolveTenant(Web) until approved.
 *   2. The generic Company row TenantDatabaseSeeder seeds into every new
 *      tenant schema is immediately overwritten with what the registrant
 *      actually typed, reusing the same field shape
 *      App\Http\Requests\UpdateCompanyRequest validates.
 *
 * Shared by App\Http\Controllers\RegisterController::store() (brand-new
 * User + workspace in one submit) and ::storeWorkspace() (an
 * already-authenticated Google user who has no workspace yet) — both need
 * identical tenant/company/TenantUser provisioning, differing only in
 * whether a User also needs to be created first.
 */
class WorkspaceProvisioningService
{
    /**
     * @param  array{company_name: string, company_location: string, company_phone: string, momo_number: ?string, momo_name: ?string, workspace_slug: string}  $data
     */
    public function provision(User $owner, array $data): Tenant
    {
        $slug = $data['workspace_slug'];

        // Fires Stancl's TenantCreated event pipeline (CreateDatabase ->
        // MigrateDatabase -> SeedDatabase) exactly like
        // DatabaseSeeder::run() and TenantController::store() — creates the
        // tenant_{slug} Postgres schema, runs the tenant migrations, and
        // runs TenantDatabaseSeeder (zones, expense categories, a generic
        // placeholder Company row).
        $tenant = Tenant::create([
            'id' => $slug,
            'name' => $data['company_name'],
            'slug' => $slug,
            'registration_status' => 'pending',
        ]);

        tenancy()->initialize($tenant);

        try {
            // The registrant fully owns their own workspace. This does NOT
            // grant landlord access — App\Http\Middleware\EnsureLandlord
            // hard-codes its check to the 'swecom' tenant id specifically,
            // so 'super' inside this brand-new tenant never passes it. See
            // self-service-onboarding.md section 6.
            TenantUser::create([
                'user_id' => $owner->id,
                'tenant_id' => $tenant->id,
                'role' => 'super',
            ]);

            // Overwrite TenantDatabaseSeeder's generic placeholder Company
            // row with what the registrant actually submitted. Only the
            // fields the registration form collects are touched — email,
            // tech_number, head office, registration numbers, and logo are
            // left as seeded/blank, matching the fixed contract this
            // controller was built against; the registrant fills those in
            // later via Settings > Company Info if needed.
            Company::query()->first()?->update([
                'name' => $data['company_name'],
                'location' => $data['company_location'],
                'phone' => $data['company_phone'],
                'momo_number' => $data['momo_number'] ?? null,
                'momo_name' => $data['momo_name'] ?? null,
            ]);
        } finally {
            tenancy()->end();
        }

        return $tenant;
    }
}
