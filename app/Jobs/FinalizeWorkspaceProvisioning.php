<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Last step of the `TenantCreated` job pipeline (App\Providers\
 * TenancyServiceProvider), after Stancl's CreateDatabase → MigrateDatabase
 * → SeedDatabase. By the time this runs the tenant's Postgres schema exists
 * and is migrated + seeded, so it's safe to initialize tenancy and write
 * the two tenant-scoped rows self-service registration needs:
 *
 *   1. the owner's `tenant_users` membership (role `super`), and
 *   2. their submitted company details onto the seeded placeholder
 *      `companies` row.
 *
 * The owner id + company form fields were stashed on `tenants.data`
 * (VirtualColumn) by App\Services\WorkspaceProvisioningService::provision()
 * because the pipeline is queued now — the HTTP request that registers a
 * workspace returns immediately instead of blocking for the full run of
 * every tenant migration (which timed out behind Nginx/FastCGI on a
 * pooled remote database). This job clears those transient keys when done.
 */
class FinalizeWorkspaceProvisioning implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(protected Tenant $tenant) {}

    public function handle(): void
    {
        $tenant = $this->tenant;

        $ownerId = $tenant->pending_owner_id;
        $company = (array) ($tenant->pending_company ?? []);

        if ($ownerId === null) {
            // Not a self-service registration (e.g. a Landlord "Add Tenant"
            // flow, which creates the TenantUser itself) — nothing to do.
            return;
        }

        tenancy()->initialize($tenant);

        try {
            TenantUser::query()->firstOrCreate(
                ['user_id' => (int) $ownerId, 'tenant_id' => $tenant->id],
                ['role' => 'super'],
            );

            if ($company !== []) {
                // Exactly what the registrant entered — an empty MoMo stays
                // empty (never another workspace's details). The company email
                // defaults to the owner's sign-up email; both are editable in
                // Settings → Company.
                Company::query()->first()?->update([
                    'name' => mb_substr((string) ($company['company_name'] ?? $tenant->name), 0, 50),
                    'location' => (string) ($company['company_location'] ?? ''),
                    'phone' => (string) ($company['company_phone'] ?? ''),
                    'email' => User::query()->find((int) $ownerId)?->email,
                    'momo_number' => ($company['momo_number'] ?? null) ?: null,
                    'momo_name' => ($company['momo_name'] ?? null) ?: null,
                ]);

                Company::forgetCache();
            }
        } finally {
            tenancy()->end();
        }

        // One-shot transient data — null it now that it's applied. A null
        // pending_owner_id is also this job's idempotency guard (see the
        // early return above), so a retry after a partial failure is safe.
        $tenant->pending_owner_id = null;
        $tenant->pending_company = null;
        $tenant->save();
    }
}
