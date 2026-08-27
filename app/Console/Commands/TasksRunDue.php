<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ScheduledTaskType;
use App\Models\ScheduledTask;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The one real cron tick for the generic task scheduler (task-scheduler.md
 * sections 1 and 3), registered every 15 minutes in routes/console.php.
 *
 * Per tenant (via Stancl's runForMultiple() — the same tenant-iteration
 * helper App\Console\Commands\SeedDemoData documents as this app's
 * established pattern), for every enabled scheduled_tasks row: ask that
 * task type's own isDue() whether it should run right now, and if so,
 * dispatch it. This command's job is purely "is it time" — it never knows
 * *how* a task type's own work runs (see App\Contracts\ScheduledTaskType).
 *
 * Defensively wraps BOTH the per-tenant loop and each individual task
 * inside it in their own try/catch, mirroring ManuscriptCalculate's
 * per-record defensive style (task-scheduler.md section 3): one
 * misconfigured tenant, or one task type throwing during isDue()/run(),
 * must never block another tenant's or another task type's due work in the
 * same tick.
 */
class TasksRunDue extends Command
{
    protected $signature = 'tasks:run-due';

    protected $description = 'Run every enabled scheduled task that is due right now, across every tenant';

    public function handle(): int
    {
        /** @var array<string, class-string<ScheduledTaskType>> $registry */
        $registry = config('scheduled_tasks.task_types', []);

        tenancy()->runForMultiple(null, function (Tenant $tenant) use ($registry): void {
            try {
                $this->runForTenant($tenant, $registry);
            } catch (Throwable $e) {
                $this->error("tasks:run-due: tenant [{$tenant->getTenantKey()}] failed: {$e->getMessage()}");
                report($e);
            }
        });

        return self::SUCCESS;
    }

    /**
     * @param  array<string, class-string<ScheduledTaskType>>  $registry
     */
    private function runForTenant(Tenant $tenant, array $registry): void
    {
        $now = Carbon::now();

        ScheduledTask::query()->where('enabled', true)->get()->each(function (ScheduledTask $task) use ($registry, $now, $tenant): void {
            $handlerClass = $registry[$task->task_type] ?? null;

            if ($handlerClass === null) {
                // Registered as a row (e.g. seeded ahead of time) but has no
                // real handler in config('scheduled_tasks.task_types') yet —
                // config/scheduled_tasks.php's doc comment is explicit that
                // an entry there is what makes a task type real. Skip quietly
                // rather than erroring on a task type that's simply not
                // built yet.
                return;
            }

            try {
                /** @var ScheduledTaskType $handler */
                $handler = app($handlerClass);

                if ($handler->isDue($task, $now)) {
                    $this->info("tasks:run-due: dispatching [{$task->task_type}] for tenant [{$tenant->getTenantKey()}].");
                    $handler->run($task);
                }
            } catch (Throwable $e) {
                $this->error("tasks:run-due: [{$task->task_type}] failed for tenant [{$tenant->getTenantKey()}]: {$e->getMessage()}");
                report($e);
            }
        });
    }
}
