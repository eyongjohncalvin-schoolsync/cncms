<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expenditures & spending controls (docs/plans/expenditures-and-spending-controls.md,
 * E5): tops `budgets.manage` up onto the existing admin and manager system
 * roles, matching what DefaultRolesSeeder now seeds a fresh schema with.
 * The owner (super) bypasses every check.
 *
 * Same additive, idempotent shape as 2026_10_22_190041_grant_installation_permissions:
 * only system roles, insertOrIgnore on the (role_id, permission) key, a role
 * an admin has customised keeps everything else it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['admin', 'manager'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('is_system', true)->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => Permission::BudgetsManage->value,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', Permission::BudgetsManage->value)->delete();
    }
};
