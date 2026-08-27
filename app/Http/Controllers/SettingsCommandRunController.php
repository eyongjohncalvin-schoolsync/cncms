<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateScheduledTaskRequest;
use App\Models\CommandRun;
use App\Models\ScheduledTask;
use App\Services\ManuscriptGenerationBatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings — Command Runs (web-admin-spec.md section 3.14 nav entry):
 * history of manuscript:calculate (and, since task-scheduler.md, any
 * scheduled task's) executions, plus — extending the same page rather than
 * adding a new nav item, per task-scheduler.md section 4's admin UI note —
 * the manuscript_generation schedule config and the pending_review ->
 * published review gate (Preview/Publish).
 *
 * Still no Repository layer for the read side: this remains a single
 * settings-page listing, same rationale as the original doc comment. The
 * write actions below (updateSchedule/publish) are thin enough (one model
 * update, one service call) not to warrant one either.
 */
class SettingsCommandRunController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', CommandRun::class);

        $paginator = CommandRun::query()->latest('ran_at')->paginate(25);

        $batchProgressById = $this->batchProgress($paginator->pluck('batch_id')->filter()->all());

        $paginator->through(fn (CommandRun $run): array => [
            'uuid' => $run->uuid,
            'command' => $run->command,
            'period' => $run->period,
            'ran_at' => $run->ran_at,
            'metadata' => $run->metadata,
            'status' => $run->status,
            // Only the aggregate summary is sent to the frontend, never the
            // full per-customer computed_result.customers map (up to ~550
            // entries) — the Preview UI is a summary, not a raw data dump,
            // matching how `metadata` was already summarized rather than
            // shown as raw JSON.
            'computed_result_summary' => $run->computed_result['summary'] ?? null,
            'batch_progress' => $batchProgressById[$run->batch_id] ?? null,
            'published_at' => $run->published_at,
        ]);

        $array = $paginator->toArray();

        $manuscriptSchedule = ScheduledTask::query()->firstOrNew(['task_type' => 'manuscript_generation']);

        return Inertia::render('Settings/CommandRuns', [
            'runs' => [
                'data' => $array['data'],
                'links' => $array['links'],
                'meta' => [
                    'current_page' => $array['current_page'],
                    'per_page' => $array['per_page'],
                    'total' => $array['total'],
                    'last_page' => $array['last_page'],
                ],
            ],
            'manuscriptSchedule' => [
                'enabled' => $manuscriptSchedule->enabled ?? false,
                'day_of_month' => $manuscriptSchedule->schedule_config['day_of_month'] ?? 25,
                'last_run_at' => $manuscriptSchedule->last_run_at,
                'next_run_at' => $manuscriptSchedule->next_run_at,
            ],
            'canManageSchedule' => Auth::user()?->can('manageSchedule', CommandRun::class) ?? false,
            'canPublish' => Auth::user()?->can('publish', CommandRun::class) ?? false,
        ]);
    }

    /**
     * Settings > Command Runs' day-of-month picker for manuscript_generation
     * (task-scheduler.md section 4). Only one ScheduledTask row exists for
     * this task type per tenant (unique `task_type` constraint) —
     * firstOrCreate keeps this endpoint idempotent whether or not the row
     * already exists yet.
     */
    public function updateSchedule(UpdateScheduledTaskRequest $request): RedirectResponse
    {
        $task = ScheduledTask::query()->firstOrCreate(['task_type' => 'manuscript_generation']);

        $task->update([
            'enabled' => $request->boolean('enabled'),
            'schedule_config' => ['day_of_month' => $request->integer('day_of_month')],
        ]);

        return redirect()->route('settings.command-runs.index')->with('success', 'Manuscript generation schedule updated.');
    }

    /**
     * Publish action (task-scheduler.md section 4's admin UI) — commits a
     * `pending_review` run's exact previously-computed numbers to the live
     * `manuscripts` table. Gated to CommandRunPolicy::publish() ("whatever
     * role already triggers manual runs today").
     */
    public function publish(CommandRun $run, ManuscriptGenerationBatchService $batches): RedirectResponse
    {
        $this->authorize('publish', CommandRun::class);

        if (! $run->isPendingReview()) {
            return redirect()->route('settings.command-runs.index')->with('error', 'Only a run awaiting review can be published.');
        }

        $batches->publish($run, Auth::id());

        return redirect()->route('settings.command-runs.index')->with('success', "Manuscript period {$run->period} published.");
    }

    /**
     * @param  array<int, string>  $batchIds
     * @return array<string, array{total: int, pending: int, failed: int, finished: bool}>
     */
    private function batchProgress(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        return DB::table('job_batches')
            ->whereIn('id', $batchIds)
            ->get()
            ->keyBy('id')
            ->map(fn ($row): array => [
                'total' => (int) $row->total_jobs,
                'pending' => (int) $row->pending_jobs,
                'failed' => (int) $row->failed_jobs,
                'finished' => $row->finished_at !== null,
            ])
            ->all();
    }
}
