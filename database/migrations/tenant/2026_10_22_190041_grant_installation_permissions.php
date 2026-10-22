<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installation fees, phase 1 (docs/plans/installation-and-promotions.md):
 * tops the two new installation permissions up onto the existing system
 * roles, matching what DefaultRolesSeeder now seeds a fresh schema with —
 * admin gets both, manager may open charges and ask for waivers but not
 * approve them (D8: admin/owner approval). Super bypasses every check.
 *
 * Same additive, idempotent shape as
 * 2026_10_20_000000_grant_cash_controls_permissions_and_cashier_role: only
 * system roles, insertOrIgnore on the (role_id, permission) key, a role an
 * admin has customised keeps everything else it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'admin' => [Permission::InstallationsManage, Permission::InstallationsApprove],
            'manager' => [Permission::InstallationsManage],
        ];

        foreach ($grants as $roleName => $permissions) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('is_system', true)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission' => $permission->value,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission', [Permission::InstallationsManage->value, Permission::InstallationsApprove->value])
            ->delete();
    }
};
