<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Branches v2 P4 (D8): tops the new `salaries.manage` permission up onto the
 * existing `admin` and `manager` system roles, matching DefaultRolesSeeder.
 * Same additive, idempotent shape as 2026_10_24_155916_grant_branch_settings_permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->whereIn('name', ['admin', 'manager'])->where('is_system', true)->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => Permission::SalariesManage->value,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', Permission::SalariesManage->value)->delete();
    }
};
