<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuration for the generic task scheduler (task-scheduler.md
     * section 2) — one row per `task_type` per tenant ("one tenant-wide
     * schedule per task type", section 7). This is the *configuration*
     * table (when should a task run); `command_runs` remains the
     * *history* table (what actually happened when it ran) — deliberately
     * not conflated into one table per the spec.
     *
     * `next_run_at` is a cached/display-only value. `tasks:run-due`
     * (App\Console\Commands\TasksRunDue) never reads it to decide whether a
     * task is due — the real due-check always re-derives from
     * `schedule_config` + `last_run_at` at tick time (see
     * App\Contracts\ScheduledTaskType::isDue()), so a manually-edited or
     * stale `next_run_at` can never desync the actual schedule.
     */
    public function up(): void
    {
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            // Closed set enforced in code (App\Support\ScheduledTasks\ScheduledTaskRegistry),
            // not a DB enum/check constraint — new task types are added by
            // registering a handler in config/scheduled_tasks.php, not by a
            // migration, so a DB-level enum would need editing on every new
            // task type for no real safety benefit here.
            $table->string('task_type', 60);
            $table->boolean('enabled')->default(true);
            $table->jsonb('schedule_config')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique('task_type', 'uq_scheduled_tasks_task_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
