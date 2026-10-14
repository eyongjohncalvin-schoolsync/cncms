<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tops up the cash-desk permissions onto the existing admin and
     * manager roles (DefaultRolesSeeder never re-touches a role that
     * already has rows). `cash.approve_large` and `periods.lock` stay with
     * the owner (super bypasses) unless handed out deliberately through
     * Roles & permissions. Idempotent (insertOrIgnore).
     */
    public function up(): void
    {
        foreach (DB::table('roles')->whereIn('name', ['admin', 'manager'])->pluck('id') as $roleId) {
            foreach ([Permission::CashView, Permission::CashManage] as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => $permission->value]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission', [
                Permission::CashView->value,
                Permission::CashManage->value,
                Permission::CashApproveLarge->value,
                Permission::PeriodsLock->value,
            ])
            ->delete();
    }
};
