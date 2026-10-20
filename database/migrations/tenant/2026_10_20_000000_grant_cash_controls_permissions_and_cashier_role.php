<?php

declare(strict_types=1);

use App\Auth\Permission;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cash controls (docs/plans/cash-controls.md): creates the new `cashier`
     * system role on schemas seeded before it existed, and tops up the new
     * receipts/cash/vouchers permissions onto the existing system roles.
     *
     * DefaultRolesSeeder::run() only creates a missing role and seeds a role
     * with ZERO permission rows, so running it here adds `cashier` without
     * touching any role an admin has customised. The top-ups below then do
     * the additive inserts for roles that already have rows — same reasoning
     * as 2026_10_14_000100_grant_opening_balances_permissions. A custom role
     * someone already named `cashier` is matched by name and left alone.
     * Idempotent (insertOrIgnore on the (role_id, permission) key).
     */
    public function up(): void
    {
        (new DefaultRolesSeeder)->run();

        $grants = [
            'admin' => [
                Permission::ReceiptsManageStock, Permission::CashView, Permission::CashRemit, Permission::CashReceive,
                Permission::VouchersRequest, Permission::VouchersApprove, Permission::VouchersPay,
            ],
            'manager' => [
                Permission::ReceiptsManageStock, Permission::CashView, Permission::CashRemit,
                Permission::VouchersRequest, Permission::VouchersApprove,
            ],
            'agent' => [Permission::CashRemit, Permission::VouchersRequest],
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
            ->whereIn('permission', array_map(fn (Permission $permission): string => $permission->value, [
                Permission::ReceiptsManageStock, Permission::CashView, Permission::CashRemit, Permission::CashReceive,
                Permission::VouchersRequest, Permission::VouchersApprove, Permission::VouchersPay,
            ]))
            ->delete();
    }
};
