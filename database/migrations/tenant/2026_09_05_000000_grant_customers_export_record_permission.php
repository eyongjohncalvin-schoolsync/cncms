<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tops up the new `customers.export_record` permission (added to the
     * catalog for docs/plans/customer-record-export.md) onto the `admin`
     * system role for schemas that were already seeded before the catalog
     * change.
     *
     * Why a migration and not just DefaultRolesSeeder: that seeder only
     * (re)seeds a role that currently has ZERO permission rows, so on the
     * live `tenantswecom` schema — where `admin` already holds its full
     * seeded matrix — adding a case to the catalog would never reach the
     * existing role. This does the additive insert directly, and is
     * idempotent (insertOrIgnore on the (role_id, permission) primary key),
     * so it is a no-op on a tenant provisioned after the catalog change
     * (where the seeder already included it via Permission::values()) and
     * safe to re-run.
     *
     * `super` needs no row — it bypasses every check via Gate::before /
     * TenantContext::isSuper(). `manager` / `agent` / `worker` are
     * deliberately NOT granted this: a full unredacted customer data dump is
     * a super/admin action (see the Permission enum case's doc comment). It
     * also never touches a custom role, or a role an admin later
     * de-selected this permission from.
     */
    public function up(): void
    {
        $permission = Permission::CustomersExportRecord->value;

        $roleIds = DB::table('roles')
            ->where('name', 'admin')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => $permission,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission', Permission::CustomersExportRecord->value)
            ->delete();
    }
};
