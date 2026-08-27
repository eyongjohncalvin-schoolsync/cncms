<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $status 'queued'|'pending_review'|'published'|'failed' — see the
 *                           2026_08_25_200010_add_scheduling_fields_to_command_runs_table
 *                           migration's docblock for the full state meaning.
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
}
