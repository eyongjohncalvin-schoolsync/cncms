<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `complaint_escalation_check` is system-owned — "always present,
     * always enabled, not something an admin can disable via this UI"
     * (references/task-scheduler.md section 5). Unlike `manuscript_generation`,
     * whose scheduled_tasks row is lazily created the first time an admin
     * visits/saves Settings -> Command Runs
     * (App\Http\Controllers\SettingsCommandRunController::edit()/
     * updateSchedule()'s firstOrNew()/firstOrCreate() calls), there is no
     * settings UI for this task type to lazily create it through — so it is
     * seeded here instead, once, for every tenant this migration runs
     * against (both already-provisioned tenants via `tenants:migrate`, and
     * automatically for any tenant provisioned after this migration exists).
     *
     * `uuid` is left unset — the column's own DB-level
     * `default(DB::raw('gen_random_uuid()'))` (see the scheduled_tasks
     * migration) fills it in without this migration needing to generate one
     * itself. `insertOrIgnore` against the `uq_scheduled_tasks_task_type`
     * unique index makes this safe to be idempotent if ever re-run.
     */
    public function up(): void
    {
        DB::table('scheduled_tasks')->insertOrIgnore([
            'task_type' => 'complaint_escalation_check',
            'enabled' => true,
            'schedule_config' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('scheduled_tasks')->where('task_type', 'complaint_escalation_check')->delete();
    }
};
