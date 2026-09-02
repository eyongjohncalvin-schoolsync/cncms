<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tops up the new `services.manage` permission (added to the catalog for
     * services.md) onto the `admin` system role for schemas seeded before
     * the catalog change.
     *
     * Same reasoning as 2026_09_05_000000_grant_customers_export_record_permission:
     * DefaultRolesSeeder only (re)seeds a role with ZERO permission rows, so
     * on the live `tenantswecom` schema — where `admin` already holds its
     * full seeded matrix — a new catalog case would never reach it. This
     * does the additive insert directly, idempotent via insertOrIgnore on
     * the (role_id, permission) primary key.
     *
     * `super` needs no row (Gate::before bypass). `manager` is deliberately
     * NOT granted it — catalogue pricing is an admin decision (services.md
     * section 6). Custom roles and roles an admin later de-selected this
     * from are untouched.
     */
    public function up(): void
    {
        $permission = Permission::ServicesManage->value;

        $roleIds = DB::table('roles')->where('name', 'admin')->pluck('id');

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
            ->where('permission', Permission::ServicesManage->value)
            ->delete();
    }
};
