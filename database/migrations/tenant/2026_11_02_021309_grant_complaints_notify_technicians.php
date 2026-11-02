<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Notify technicians" (owner, 2026-11-02): a complaint logged by someone
 * holding it rings the branch's field technicians at once. Topped up onto
 * the existing agent, manager and admin roles (the seeder only fills empty
 * roles — see the customers.export_record grant migration); any other role
 * can be given it in Roles & permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->whereIn('name', ['agent', 'manager', 'admin'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => Permission::ComplaintsNotifyTechnicians->value,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', Permission::ComplaintsNotifyTechnicians->value)->delete();
    }
};
