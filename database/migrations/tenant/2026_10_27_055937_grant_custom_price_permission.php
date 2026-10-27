<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tops the new `customers.custom_price` permission (typing a service price
 * of one's own) up onto the existing `admin` and `manager` system roles,
 * matching DefaultRolesSeeder. Same additive, idempotent shape as
 * 2026_10_24_173532_grant_salaries_permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->whereIn('name', ['admin', 'manager'])->where('is_system', true)->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => Permission::CustomersCustomPrice->value,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', Permission::CustomersCustomPrice->value)->delete();
    }
};
