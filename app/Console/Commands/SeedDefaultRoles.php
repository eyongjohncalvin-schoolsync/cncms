<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Console\Command;

/**
 * Seeds / re-seeds the 5 RBAC v2 system roles for one tenant or all
 * tenants. Safe to re-run against the live `tenantswecom` schema — the
 * underlying Database\Seeders\DefaultRolesSeeder matches roles by name,
 * only populates permissions for a role with zero rows, and never deletes
 * anything, so a role an admin has customised via the matrix UI is left
 * untouched.
 *
 *   php artisan cncms:seed-default-roles            # every tenant
 *   php artisan cncms:seed-default-roles swecom     # just this one
 *
 * Run from the Laravel Cloud command runner, same as cncms:tenant-role.
 */
class SeedDefaultRoles extends Command
{
    protected $signature = 'cncms:seed-default-roles {tenant? : Tenant id / slug; omit to seed every tenant}';

    protected $description = 'Seed the default RBAC v2 system roles (super/admin/manager/agent/worker) into a tenant schema';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');

        $tenants = $tenantId !== null
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error($tenantId !== null ? "No tenant [{$tenantId}]." : 'No tenants found.');

            return self::FAILURE;
        }

        tenancy()->runForMultiple($tenants, function (Tenant $tenant): void {
            (new DefaultRolesSeeder)->run();
            $this->info("Seeded default roles for [{$tenant->id}].");
        });

        return self::SUCCESS;
    }
}
