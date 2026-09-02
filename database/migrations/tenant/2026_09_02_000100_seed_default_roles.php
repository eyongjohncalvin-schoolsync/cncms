<?php

declare(strict_types=1);

use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seed the 5 RBAC v2 system roles into every tenant schema that runs
     * migrations — the already-provisioned `tenantswecom` on the next
     * `tenants:migrate`, and every future tenant during provisioning
     * (MigrateDatabase runs before SeedDatabase).
     *
     * Same reasoning as 2026_08_26_040020_seed_push_receipt_check_scheduled_task:
     * seed data with no settings-UI origin belongs in a migration, not a
     * form. Delegates to Database\Seeders\DefaultRolesSeeder, which is
     * idempotent (roles matched by name, permissions only seeded for a role
     * with zero rows, nothing ever deleted) — so this is a no-op if the
     * standalone `cncms:seed-default-roles` command already ran first.
     */
    public function up(): void
    {
        (new DefaultRolesSeeder)->run();
    }

    public function down(): void
    {
        // Leave the seeded rows in place — role_permissions has no FK from
        // tenant_users (that column is a plain string), so dropping roles
        // here would strand no data, but a rollback of this migration
        // without also rolling back the create-tables migration is not a
        // real scenario worth destroying an admin's customised matrix for.
    }
};
