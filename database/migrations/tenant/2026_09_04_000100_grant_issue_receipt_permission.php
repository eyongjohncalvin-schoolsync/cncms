<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tops up the new `payments.issue_receipt` permission (added to the
     * catalog for Wave 2 of docs/plans/payment-receipts-and-whatsapp.md)
     * onto the `admin` and `manager` system roles — the same roles that
     * already hold `payments.verify`.
     *
     * Why a migration and not just DefaultRolesSeeder: that seeder only
     * (re)seeds a role that currently has ZERO permission rows, so on the
     * live `tenantswecom` schema — where admin/manager already have their
     * full seeded matrix — adding a case to the catalog would never reach
     * the existing roles. This does the additive insert directly, and is
     * idempotent (insertOrIgnore on the (role_id, permission) primary key),
     * so it is a no-op on a tenant provisioned after the catalog change
     * (where the seeder already included it) and safe to re-run.
     *
     * Deliberately does NOT touch a custom role or a role an admin has
     * de-selected this permission from later — it only ever adds the row if
     * missing for the two named system roles.
     */
    public function up(): void
    {
        $permission = Permission::PaymentsIssueReceipt->value;

        $roleIds = DB::table('roles')
            ->whereIn('name', ['admin', 'manager'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => $permission,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission', Permission::PaymentsIssueReceipt->value)
            ->delete();
    }
};
