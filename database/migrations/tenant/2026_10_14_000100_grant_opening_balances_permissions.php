<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tops up the new `opening_balances.view` / `opening_balances.enter`
     * permissions onto the existing `admin` and `manager` system roles —
     * same reasoning as 2026_09_04_000100_grant_issue_receipt_permission:
     * DefaultRolesSeeder never re-touches a role that already has rows.
     *
     * `opening_balances.approve` is deliberately NOT granted to any role:
     * approval is the owner's (super bypasses), and is handed to extra hands
     * explicitly through Roles & permissions. Idempotent (insertOrIgnore).
     */
    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('name', ['admin', 'manager'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ([Permission::OpeningBalancesView, Permission::OpeningBalancesEnter] as $permission) {
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
            ->whereIn('permission', [
                Permission::OpeningBalancesView->value,
                Permission::OpeningBalancesEnter->value,
                Permission::OpeningBalancesApprove->value,
            ])
            ->delete();
    }
};
