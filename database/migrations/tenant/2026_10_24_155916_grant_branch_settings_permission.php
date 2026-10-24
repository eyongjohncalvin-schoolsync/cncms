<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Branches v2 P3 (D4): tops the new `branches.settings` permission — set
 * one's own branch's contact details, MoMo number, fees and prices — up onto
 * the existing `admin` and `manager` system roles, matching what
 * DefaultRolesSeeder seeds a fresh schema with. Super bypasses every check.
 *
 * Same additive, idempotent shape as
 * 2026_10_23_104111_grant_installation_offers_permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->whereIn('name', ['admin', 'manager'])->where('is_system', true)->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => Permission::BranchesSettings->value,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', Permission::BranchesSettings->value)->delete();
    }
};
