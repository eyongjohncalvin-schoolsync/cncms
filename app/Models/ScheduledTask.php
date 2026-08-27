<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configuration row for the generic task scheduler (task-scheduler.md
 * section 2) — one per `task_type` per tenant. See
 * App\Console\Commands\TasksRunDue for the cron tick that reads this table,
 * and App\Contracts\ScheduledTaskType for how each task type interprets its
 * own `schedule_config`.
 *
 * `enabled` is admin-toggleable UI state; it is NOT the same as "system-
 * owned, always present" task types like `complaint_escalation_check`
 * (task-scheduler.md section 5) simply never exposing an enabled toggle in
 * the UI — this column exists on every row regardless, a task type just
 * chooses whether its own settings UI lets an admin flip it.
 *
 * @property string $task_type
 * @property bool $enabled
 * @property array<string, mixed>|null $schedule_config
 */
#[Fillable(['task_type', 'enabled', 'schedule_config', 'last_run_at', 'next_run_at'])]
#[RouteKey('uuid')]
class ScheduledTask extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'schedule_config' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function commandRuns(): HasMany
    {
        return $this->hasMany(CommandRun::class);
    }
}
