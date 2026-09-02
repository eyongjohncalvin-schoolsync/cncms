<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;

/**
 * Provisions a brand-new self-service workspace (Tenant), following the
 * pattern proven by database/seeders/DatabaseSeeder.php and
 * App\Http\Controllers\Landlord\TenantController::store() — with two
 * self-service specifics (see references/self-service-onboarding.md):
 *
 *   1. `registration_status` is set to 'pending' (a public signup is
 *      untrusted until a landlord reviews it), gating dashboard access via
 *      ResolveTenant(Web) until approved.
 *   2. The owner's `tenant_users` membership (role `super`) and their
 *      submitted company details are written to the new tenant schema.
 *
 * (2) does NOT happen here anymore. Creating a tenant fires Stancl's
 * `TenantCreated` pipeline (CreateDatabase → MigrateDatabase → SeedDatabase
 * → App\Jobs\FinalizeWorkspaceProvisioning), and that pipeline is now
 * QUEUED (App\Providers\TenancyServiceProvider) — running every tenant
 * migration inline blew past the HTTP/FastCGI timeout on a pooled remote
 * database. So this method only inserts the `tenants` row (with the owner
 * id + company form data stashed on `tenants.data` for the finalize job to
 * pick up) and returns immediately. The tenant schema is built, and the
 * membership + company row written, on the queue worker.
 *
 * Shared by RegisterController::store() (new User + workspace in one
 * submit) and ::storeWorkspace() (a Google user with no workspace yet).
 */
class WorkspaceProvisioningService
{
    /**
     * @param  array{company_name: string, company_location: string, company_phone: string, momo_number: ?string, momo_name: ?string, workspace_slug: string}  $data
     */
    public function provision(User $owner, array $data): Tenant
    {
        $slug = $data['workspace_slug'];

        // VirtualColumn: `pending_owner_id` / `pending_company` land in the
        // `tenants.data` JSON. FinalizeWorkspaceProvisioning (last job in
        // the TenantCreated pipeline, after the schema is migrated + seeded)
        // reads them, writes the tenant_users + companies rows, then nulls
        // them out.
        return Tenant::create([
            'id' => $slug,
            'name' => $data['company_name'],
            'slug' => $slug,
            'registration_status' => 'pending',
            'pending_owner_id' => $owner->id,
            'pending_company' => [
                'company_name' => $data['company_name'],
                'company_location' => $data['company_location'],
                'company_phone' => $data['company_phone'],
                'momo_number' => $data['momo_number'] ?? null,
                'momo_name' => $data['momo_name'] ?? null,
            ],
        ]);
    }
}
