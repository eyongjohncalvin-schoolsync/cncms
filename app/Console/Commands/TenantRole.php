<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Sets a central user's role inside a tenant — creating the `tenant_users`
 * membership row if it doesn't exist.
 *
 *   php artisan cncms:tenant-role swecom you@example.com super
 *
 * Two uses:
 *   1. The Landlord "Add Tenant" flow (Landlord\TenantController::store)
 *      provisions the schema but assigns NO owner — this is how you make
 *      someone the `super` of a landlord-created workspace.
 *   2. Fixing / changing a user's role in a workspace when there's no UI
 *      path (e.g. bootstrapping, or a self-service owner who needs a
 *      second admin before the Users Control Center screen is reachable).
 *
 * RBAC v2 Wave 4: the role name is validated against the tenant's own
 * `roles` table (system + custom), not a hardcoded list of the 5 built-ins
 * — so this can assign a tenant-defined custom role too. The check happens
 * after `tenancy()->initialize()` so the `roles` table is the tenant's.
 *
 * Run from the Laravel Cloud command runner.
 */
class TenantRole extends Command
{
    protected $signature = 'cncms:tenant-role
        {tenant : The tenant id / slug (e.g. swecom)}
        {email : The central user\'s email}
        {role : A role name from the tenant\'s roles table (e.g. super, admin, or a custom role)}';

    protected $description = "Set a user's role in a tenant (creates the membership if missing)";

    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant');
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $role = mb_strtolower(trim((string) $this->argument('role')));

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("No tenant [{$tenantId}].");

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email [{$email}].");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $availableRoles = Role::query()->orderBy('name')->pluck('name')->all();

            if (! in_array($role, $availableRoles, true)) {
                $this->error(
                    "No role [{$role}] in tenant [{$tenantId}]. Available roles: "
                    .(count($availableRoles) > 0 ? implode(', ', $availableRoles) : '(none — run cncms:seed-default-roles)').'.'
                );

                return self::FAILURE;
            }

            $membership = TenantUser::query()->updateOrCreate(
                ['user_id' => $user->id, 'tenant_id' => $tenant->id],
                ['role' => $role],
            );
        } finally {
            tenancy()->end();
        }

        $verb = $membership->wasRecentlyCreated ? 'Added' : 'Updated';
        $this->info("{$verb} {$user->name} <{$email}> as [{$role}] in tenant [{$tenantId}].");

        return self::SUCCESS;
    }
}
