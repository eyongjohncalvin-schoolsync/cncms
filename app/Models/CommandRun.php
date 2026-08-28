<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $status 'queued'|'pending_review'|'published'|'failed'|'rolled_back' — see the
 *                           2026_08_25_200010_add_scheduling_fields_to_command_runs_table
 *                           migration's docblock for the full state meaning,
 *                           and SettingsCommandRunController::rollback() for
 *                           'rolled_back' (added 2026-08-28, plain string
 *                           column — status has no DB check constraint to
 *                           extend).
 * @property array<string, mixed>|null $computed_result The durable, per-customer
 *                           computed result set a chunked batch run writes (see
 *                           App\Jobs\ComputeManuscriptChunkJob /
 *                           App\Services\ManuscriptGenerationBatchService) —
 *                           what Publish commits verbatim, never a fresh recomputation.
 */
#[Fillable(['command', 'period', 'ran_at', 'metadata', 'status', 'computed_result', 'scheduled_task_id', 'batch_id', 'published_at', 'published_by'])]
#[RouteKey('uuid')]
class CommandRun extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'metadata' => 'array',
            'computed_result' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function scheduledTask(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class);
    }

    public function isPendingReview(): bool
    {
        return $this->status === 'pending_review';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * 'queued' is the one non-terminal status a run can get permanently
     * stuck at with no code path left to move it forward — a crashed queue
     * worker mid-batch, or a `kill -9`'d manuscript:calculate CLI process
     * (stage 1's own try/catch->'failed' handling only covers an exception
     * actually reaching PHP; a hard-killed process never runs it). See
     * SettingsCommandRunController::cancel() — the 2026-08-27 manual unstick
     * action gated to this status specifically.
     */
    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function isRolledBack(): bool
    {
        return $this->status === 'rolled_back';
    }

    /**
     * The manuscript-run-management feature's (task-scheduler.md's
     * 2026-08-28 addendum) Delete/Rollback action is offered for any status
     * that could plausibly have written real `manuscripts` rows under this
     * run's `command_run_id` — 'pending_review' (computed, awaiting
     * publish — deletes zero rows today since only publish() writes
     * `manuscripts`, but the action is still meaningful: it discards the
     * computed result and frees the period), 'published' (the normal case —
     * real rows exist), and 'failed' (a CLI run can fail partway through
     * `runForEveryCustomer()`'s per-customer loop after already committing
     * some customers' rows before the fatal exception — those partial rows
     * are real and deletable too). Excludes 'queued' (that is Cancel's job,
     * not Rollback's — a queued run has never written a `manuscripts` row)
     * and 'rolled_back' (terminal; already done).
     */
    public function isRollbackable(): bool
    {
        return in_array($this->status, ['pending_review', 'published', 'failed'], true);
    }
}
