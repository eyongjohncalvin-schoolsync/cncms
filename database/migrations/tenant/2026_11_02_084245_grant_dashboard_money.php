<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wave 4 of the permission audit (owner, 2026-11-02): the dashboard's money
 * figures — every role saw them — need "Dashboard: money". Given to the
 * roles that see the bills behind them (manuscripts.view), as in
 * App\Auth\PermissionSplits; unticked in Settings → Roles for anyone else.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('role_permissions')->where('permission', Permission::ManuscriptsView->value)->pluck('role_id')->unique();

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => Permission::DashboardMoney->value]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', Permission::DashboardMoney->value)->delete();
    }
};
