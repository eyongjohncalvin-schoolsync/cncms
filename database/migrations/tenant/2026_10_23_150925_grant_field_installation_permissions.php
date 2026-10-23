<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installation phase 3 (docs/plans/installation-and-promotions.md, D10):
 * tops the two new permissions up onto the existing system roles, matching
 * what DefaultRolesSeeder seeds a fresh schema with — the field agent may
 * register customers offline in their own zone (`installations.field`);
 * manager and admin activate or reject them (`installations.activate`).
 * Super bypasses every check.
 *
 * Same additive, idempotent shape as
 * 2026_10_22_190041_grant_installation_permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'admin' => [Permission::InstallationsActivate],
            'manager' => [Permission::InstallationsActivate],
            'agent' => [Permission::InstallationsField],
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
            ->whereIn('permission', [Permission::InstallationsField->value, Permission::InstallationsActivate->value])
            ->delete();
    }
};
