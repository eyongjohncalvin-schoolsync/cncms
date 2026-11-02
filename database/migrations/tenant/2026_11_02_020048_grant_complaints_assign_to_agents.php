<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Field agents may send a technician to a complaint (owner, 2026-11-02), so
 * it doesn't wait for a manager or admin. Tops the permission up onto the
 * existing `agent` roles (the seeder only fills empty roles — see the
 * customers.export_record grant migration); an admin can still untick it in
 * Roles & permissions. Only agents covering the complaint's zone are
 * alerted about a new one (ComplaintService::create()).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->where('name', 'agent')->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => Permission::ComplaintsAssign->value,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('role_id', DB::table('roles')->where('name', 'agent')->pluck('id'))
            ->where('permission', Permission::ComplaintsAssign->value)
            ->delete();
    }
};
