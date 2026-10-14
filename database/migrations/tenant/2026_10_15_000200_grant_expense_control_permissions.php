<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tops up the new expense-control permissions onto the existing system
     * roles (DefaultRolesSeeder never re-touches a role that already has
     * rows) — same pattern as 2026_10_14_000100_grant_opening_balances_permissions.
     *
     * - admin: approve, void, suppliers, settings (budgets / limits / templates)
     * - manager: approve, suppliers
     *
     * `expenditures.approve_large` (the second approval on big or
     * over-budget expenses) is the owner's — super bypasses — and is handed
     * to someone else only deliberately, through Roles & permissions.
     * Idempotent (insertOrIgnore).
     */
    public function up(): void
    {
        $grants = [
            'admin' => [Permission::ExpendituresApprove, Permission::ExpendituresVoid, Permission::SuppliersManage, Permission::ResourcesSettings],
            'manager' => [Permission::ExpendituresApprove, Permission::SuppliersManage],
        ];

        foreach ($grants as $roleName => $permissions) {
            foreach (DB::table('roles')->where('name', $roleName)->pluck('id') as $roleId) {
                foreach ($permissions as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => $permission->value]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission', [
                Permission::ExpendituresApprove->value,
                Permission::ExpendituresApproveLarge->value,
                Permission::ExpendituresVoid->value,
                Permission::SuppliersManage->value,
                Permission::ResourcesSettings->value,
            ])
            ->delete();
    }
};
