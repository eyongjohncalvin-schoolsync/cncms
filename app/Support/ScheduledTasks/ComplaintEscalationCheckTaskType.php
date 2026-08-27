<?php

declare(strict_types=1);

namespace App\Support\ScheduledTasks;

use App\Contracts\ScheduledTaskType;
use App\Models\ScheduledTask;
use App\Repositories\Contracts\ComplaintRepositoryInterface;
use App\Services\ComplaintEscalationService;
use Illuminate\Support\Carbon;

/**
 * The Complaint Desk's escalation checker (references/task-scheduler.md
 * section 5, references/complaint-desk.md section 3) — the second real task
 * type plugged into the generic scheduler, alongside
 * App\Support\ScheduledTasks\ManuscriptGenerationTaskType.
 *
 * System-owned: its `scheduled_tasks` row is seeded directly by the
 * 2026_08_25_210010_seed_complaint_escalation_check_scheduled_task migration
 * (always enabled, no settings UI to toggle it off) rather than lazily
 * created through an admin-facing form the way manuscript_generation's row
 * is — "escalation correctness shouldn't be one settings toggle away from
 * silently breaking" (task-scheduler.md section 5).
 *
 * isDue() always returns true: escalation-checking needs to run on every
 * `tasks:run-due` tick (every 15 minutes, per routes/console.php), not on a
 * configurable date, unlike manuscript_generation's day-of-month schedule.
 * All the actual threshold/audience/idempotency logic lives in
 * App\Services\ComplaintEscalationService — this class's only job, matching
 * App\Contracts\ScheduledTaskType's contract, is "is it time, and if so,
 * kick off the work" for each open complaint in the sweep.
 */
class ComplaintEscalationCheckTaskType implements ScheduledTaskType
{
    public function __construct(
        private readonly ComplaintRepositoryInterface $complaints,
        private readonly ComplaintEscalationService $escalations,
    ) {}

    public function taskType(): string
    {
        return 'complaint_escalation_check';
    }

    public function isDue(ScheduledTask $task, Carbon $now): bool
    {
        return true;
    }

    public function run(ScheduledTask $task): void
    {
        $now = Carbon::now();

        foreach ($this->complaints->openForEscalationSweep() as $complaint) {
            $this->escalations->sweep($complaint, $now);
        }

        // Display-only, same as ManuscriptGenerationTaskType — TasksRunDue
        // never reads this back to decide due-ness (isDue() above always
        // returns true regardless).
        $task->update(['last_run_at' => $now]);
    }
}
