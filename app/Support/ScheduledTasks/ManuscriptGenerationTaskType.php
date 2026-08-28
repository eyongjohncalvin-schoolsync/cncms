<?php

declare(strict_types=1);

namespace App\Support\ScheduledTasks;

use App\Contracts\ScheduledTaskType;
use App\Models\ScheduledTask;
use App\Services\ManuscriptGenerationBatchService;
use Illuminate\Support\Carbon;

/**
 * The first (and, for this pass, only) real scheduled task type
 * (task-scheduler.md section 4): admin-configurable day-of-month manuscript
 * generation with a WYSIWYG preview/publish gate.
 *
 * `schedule_config` shape: {"day_of_month": 1-31}. A configured day beyond
 * the current month's real length is clamped to that month's last day
 * (section 4's explicit example: a "day 30" schedule still fires once in
 * February, on the 28th/29th, rather than silently never firing).
 */
class ManuscriptGenerationTaskType implements ScheduledTaskType
{
    public function __construct(
        private readonly ManuscriptGenerationBatchService $batches,
    ) {}

    public function taskType(): string
    {
        return 'manuscript_generation';
    }

    public function isDue(ScheduledTask $task, Carbon $now): bool
    {
        $configuredDay = (int) ($task->schedule_config['day_of_month'] ?? 0);

        if ($configuredDay < 1 || $configuredDay > 31) {
            // Not configured yet (task created but no day_of_month picked in
            // Settings) — nothing to run.
            return false;
        }

        $targetDay = min($configuredDay, $now->daysInMonth);

        if ($now->day < $targetDay) {
            return false;
        }

        // Once per calendar month: a tick on day 26 (target 25) must not
        // re-dispatch a second run for the rest of the month just because
        // last_run_at is a few hours old rather than "this month".
        if ($task->last_run_at !== null && $task->last_run_at->isSameMonth($now)) {
            return false;
        }

        return true;
    }

    public function run(ScheduledTask $task): void
    {
        // 2026-08-28 correction (business-rules.md section 2): this task
        // fires near month-end (the admin-configured day_of_month), and the
        // resulting manuscript governs the NEXT calendar month, not the one
        // it fires in — see App\Console\Commands\ManuscriptCalculate's
        // identical comment. addMonthNoOverflow() so a firing on the 29th-
        // 31st doesn't skip a whole month in a shorter target month.
        $period = Carbon::now()->addMonthNoOverflow()->format('Y-m');

        $this->batches->dispatch($period, scheduledTask: $task, autoPublish: false);

        // Stamped at DISPATCH time, not when the batch actually finishes —
        // see App\Contracts\ScheduledTaskType::run()'s doc comment for why:
        // this is what prevents the next 15-minute tick from re-dispatching
        // a second batch while the first is still mid-flight.
        $task->update([
            'last_run_at' => Carbon::now(),
            'next_run_at' => $this->nextRunAt($task),
        ]);
    }

    /**
     * Display-only (see ScheduledTask's class doc — never read back to
     * decide due-ness).
     */
    private function nextRunAt(ScheduledTask $task): ?Carbon
    {
        $configuredDay = (int) ($task->schedule_config['day_of_month'] ?? 0);

        if ($configuredDay < 1) {
            return null;
        }

        $next = Carbon::now()->addMonthNoOverflow()->startOfMonth();

        return $next->day(min($configuredDay, $next->daysInMonth));
    }
}
