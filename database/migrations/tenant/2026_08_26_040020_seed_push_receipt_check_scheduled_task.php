<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `push_receipt_check` is system-owned — same reasoning as the
     * 2026_08_25_210010 migration seeding `complaint_escalation_check`:
     * there is no settings UI for this task type, so its scheduled_tasks
     * row is seeded directly here rather than lazily created through a
     * form. Always enabled, `schedule_config` empty — App\Support\
     * ScheduledTasks\PushReceiptCheckTaskType::isDue() always returns true,
     * since it needs to run on every tasks:run-due tick (every 15 minutes),
     * not on a configurable date. `insertOrIgnore` against the
     * uq_scheduled_tasks_task_type unique index keeps this idempotent if
     * ever re-run.
     */
    public function up(): void
    {
        DB::table('scheduled_tasks')->insertOrIgnore([
            'task_type' => 'push_receipt_check',
            'enabled' => true,
            'schedule_config' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('scheduled_tasks')->where('task_type', 'push_receipt_check')->delete();
    }
};
