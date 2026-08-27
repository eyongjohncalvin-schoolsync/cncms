<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ScheduledTask;
use Illuminate\Support\Carbon;

/**
 * One task type plugged into the generic scheduler (task-scheduler.md
 * section 1) — e.g. `manuscript_generation` (App\Support\ScheduledTasks\
 * ManuscriptGenerationTaskType, built here) or `complaint_escalation_check`
 * (owned by the Complaint Desk feature, not built in this pass).
 *
 * App\Console\Commands\TasksRunDue is the ONLY caller of this interface. Its
 * job is purely "is it time, and if so, kick off the work" — it never knows
 * *how* a task type's actual job runs. Register new implementations in
 * config('scheduled_tasks.task_types') keyed by taskType(); TasksRunDue
 * resolves them from the container via that config, not by scanning for
 * implementations, so a new task type is a one-line config addition plus
 * its own class — no changes needed to TasksRunDue itself.
 */
interface ScheduledTaskType
{
    /**
     * The `scheduled_tasks.task_type` value this handler owns, e.g.
     * 'manuscript_generation'. Must match the config('scheduled_tasks.task_types')
     * key it's registered under.
     */
    public function taskType(): string;

    /**
     * Whether $task should run right now, given its own `schedule_config`
     * and `last_run_at` — re-derived fresh on every call, never trusting a
     * cached `next_run_at` (see ScheduledTask's class doc).
     *
     * Must be cheap and side-effect-free: TasksRunDue calls this for every
     * enabled scheduled_tasks row on every 15-minute tick.
     */
    public function isDue(ScheduledTask $task, Carbon $now): bool;

    /**
     * Actually kick off this task type's work for the current tick. For
     * manuscript_generation this dispatches a chunked Bus::batch() run
     * (task-scheduler.md section 4.1) and returns immediately — it does NOT
     * block until the batch finishes. Implementations own updating their
     * own `last_run_at`/`next_run_at` (TasksRunDue does not do this on
     * their behalf, since "when did this actually start" is a task-type-
     * specific concern — e.g. manuscript_generation stamps last_run_at at
     * dispatch time specifically so a batch still mid-flight on the next
     * 15-minute tick isn't re-dispatched).
     */
    public function run(ScheduledTask $task): void;
}
