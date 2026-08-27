<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateScheduledTaskRequest;
use App\Models\CommandRun;
use App\Models\ScheduledTask;
use App\Services\ManuscriptGenerationBatchService;
use App\Support\ResolvesCommandRunBatchProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
    use ResolvesCommandRunBatchProgress;

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
            // Same ability as canPublish (CommandRunPolicy::publish() is
            // reused for both — see cancel()'s doc comment) but exposed
            // under its own name so the frontend's "Cancel" button (a
            // row-level action only offered for status === 'queued', see
            // that same doc comment for why stage 3 still needs to build
            // this) doesn't have to infer its gate from an unrelated prop.
            'canCancel' => Auth::user()?->can('publish', CommandRun::class) ?? false,
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
     * The manual "unstick a permanently-queued run" action (2026-08-27
     * security-review finding): nothing anywhere in this app could ever
     * clear a `command_runs` row genuinely stuck at `status = 'queued'` — a
     * crashed queue worker mid-batch, or a `kill -9`'d manuscript:calculate
     * CLI process (stage 1's try/catch->'failed' handling only fires for an
     * exception PHP actually gets to run; a hard-killed process never
     * reaches it). Left stuck, such a row permanently blocks
     * idx_command_runs_period_inflight for that (command, period) pair —
     * every future run for that exact period would keep colliding with a
     * dead row forever.
     *
     * Gated to CommandRunPolicy::publish() — deliberately reused rather than
     * a new ability: it is already exactly "the same roles as
     * ManuscriptPolicy::calculate()" (super/admin) acting on this same
     * `command_runs` row's lifecycle, which is precisely what this action
     * is too. manageSchedule() was the other candidate but is conceptually
     * about ScheduledTask config, a different (if same-role-gated) surface —
     * publish() is the closer semantic fit.
     *
     * Deliberately NO time threshold (e.g. "only cancellable after N minutes
     * with no progress"): at this app's real scale (~6 users, all
     * super/admin for this page), a human noticing a stuck run and choosing
     * to cancel it is already the entire safety mechanism — the same
     * same-role human judgment every other admin-only destructive action in
     * this app (bulk disconnect, publish, etc.) relies on with no extra
     * cooldown. A minimum-age gate would add real complexity (what counts as
     * "no progress"? job_batches progress? a second timestamp column to
     * track?) for negligible benefit, and could actively block a legitimate
     * immediate cancel — e.g. an admin who realizes within seconds that they
     * fat-fingered the period and wants to clear it right away rather than
     * wait out an arbitrary window. Matches task-scheduler.md section 7's
     * established "keep it simple, don't over-build for a scale this app
     * doesn't have" ethos.
     *
     * Confirmed: flipping status to 'failed' immediately frees
     * idx_command_runs_period_inflight for this exact (command, period) pair
     * — that index's WHERE clause is `status IN ('queued', 'pending_review')`
     * (see that migration's own doc comment), so a 'failed' row simply falls
     * out of the partial index the instant this update commits; no separate
     * "release the lock" step is needed.
     */
    public function cancel(CommandRun $run): RedirectResponse
    {
        $this->authorize('publish', CommandRun::class);

        if (! $run->isQueued()) {
            return redirect()->route('settings.command-runs.index')->with('error', 'Only a run still stuck at "queued" can be cancelled — this one has already moved on.');
        }

        $run->update([
            'status' => 'failed',
            'metadata' => [
                ...($run->metadata ?? []),
                'cancelled_by' => Auth::id(),
                'cancelled_at' => now()->toIso8601String(),
                'cancel_reason' => 'Manually cancelled from Settings > Command Runs — stuck at queued.',
            ],
        ]);

        return redirect()->route('settings.command-runs.index')->with('success', "Manuscript period {$run->period}'s stuck run was cancelled — that period is free to run again.");
    }
}
