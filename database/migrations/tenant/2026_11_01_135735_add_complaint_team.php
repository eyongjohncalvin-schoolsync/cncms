<?php

declare(strict_types=1);

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The complaint team (owner, 2026-11-01): complaint alerts go to the roles
 * holding `complaints.receive_alerts` — admin and manager to start with
 * (super holds everything) — and, at 48 hours, to everyone in the
 * complaint's branch, instead of the whole company.
 *
 * Grants the permission to the existing admin/manager roles (the seeder
 * only fills empty roles — see the customers.export_record grant
 * migration), and lets complaint_escalations log the two new audiences:
 * 'team' and 'branch'.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->whereIn('name', ['admin', 'manager'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => Permission::ComplaintsReceiveAlerts->value,
            ]);
        }

        DB::statement('ALTER TABLE complaint_escalations DROP CONSTRAINT IF EXISTS complaint_escalations_notified_scope_check');
        DB::statement("ALTER TABLE complaint_escalations ADD CONSTRAINT complaint_escalations_notified_scope_check CHECK (notified_scope IN ('user', 'role', 'all', 'team', 'branch'))");
    }

    public function down(): void
    {
        DB::table('complaint_escalations')->whereIn('notified_scope', ['team', 'branch'])->delete();
        DB::statement('ALTER TABLE complaint_escalations DROP CONSTRAINT IF EXISTS complaint_escalations_notified_scope_check');
        DB::statement("ALTER TABLE complaint_escalations ADD CONSTRAINT complaint_escalations_notified_scope_check CHECK (notified_scope IN ('user', 'role', 'all'))");

        DB::table('role_permissions')->where('permission', Permission::ComplaintsReceiveAlerts->value)->delete();
    }
};
