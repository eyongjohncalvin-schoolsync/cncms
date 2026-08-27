<?php

declare(strict_types=1);

namespace App\Support\ScheduledTasks;

use App\Contracts\ScheduledTaskType;
use App\Jobs\CheckPushReceiptsJob;
use App\Models\ScheduledTask;
use Illuminate\Support\Carbon;

/**
 * Plugs App\Jobs\CheckPushReceiptsJob into the generic scheduler
 * (task-scheduler.md section 1), exactly mirroring
 * App\Support\ScheduledTasks\ComplaintEscalationCheckTaskType: system-owned
 * (its scheduled_tasks row is seeded by the
 * 2026_08_26_040020_seed_push_receipt_check_scheduled_task migration,
 * always enabled, no settings UI to toggle it off — "not something an admin
 * can disable via this UI", task-scheduler.md section 5), and isDue()
 * always true since receipt-checking needs to run on every tasks:run-due
 * tick (every 15 minutes), not on a configurable date.
 *
 * Unlike ComplaintEscalationCheckTaskType (which does its sweep inline),
 * this dispatches a real queued job — the actual work here is a batch of
 * outbound HTTP calls to Expo, which belongs on the queue like any other
 * network-bound unit of work, not run synchronously inside the
 * `tasks:run-due` artisan process.
 */
class PushReceiptCheckTaskType implements ScheduledTaskType
{
    public function taskType(): string
    {
        return 'push_receipt_check';
    }

    public function isDue(ScheduledTask $task, Carbon $now): bool
    {
        return true;
    }

    public function run(ScheduledTask $task): void
    {
        CheckPushReceiptsJob::dispatch();

        // Display-only, same as ComplaintEscalationCheckTaskType/
        // ManuscriptGenerationTaskType — TasksRunDue never reads this back
        // to decide due-ness (isDue() above always returns true regardless).
        $task->update(['last_run_at' => Carbon::now()]);
    }
}
