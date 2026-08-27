<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ComputeManuscriptChunkJob;
use App\Models\ArrearsAdjustment;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\ScheduledTask;
use Illuminate\Bus\Batch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The chunked, queued Bus::batch() execution mechanism for
 * manuscript_generation (task-scheduler.md section 4.1) — used by BOTH the
 * scheduled path (App\Support\ScheduledTasks\ManuscriptGenerationTaskType)
 * and the manual "run now" trigger (App\Http\Controllers\ManuscriptController::calculate()).
 * Only the review GATE differs between the two callers (via $autoPublish);
 * the execution mechanism itself — chunking, Bus::batch(), allowFailures(),
 * durable computed-result storage — is identical for both, per section 4.1's
 * explicit instruction that the robustness problem applies equally to
 * manually-triggered runs.
 *
 * Compute/publish split (section 4): dispatch() only ever COMPUTES (via
 * ComputeManuscriptChunkJob chunks) and stores the result durably on the
 * command_runs row — it never itself writes to `manuscripts`. publish()
 * commits that exact stored result, never recomputing.
 */
class ManuscriptGenerationBatchService
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
    ) {}

    /**
     * Kicks off a chunked compute run for $period and returns immediately —
     * callers must not assume the run has finished by the time this
     * returns (with a real queue connection it almost certainly hasn't).
     *
     * @param  ScheduledTask|null  $scheduledTask  Null for a manual "run now" trigger.
     * @param  bool  $autoPublish  True for the manual trigger (no review gate — the batch's
     *                             then() callback publishes immediately once compute finishes,
     *                             matching manual runs' pre-existing immediate-commit behavior).
     *                             False for the scheduled path, which stops at 'pending_review'
     *                             (section 4's WYSIWYG preview/publish guarantee).
     * @param  int|null  $actingUserId  The user who triggered a manual run (for $autoPublish's
     *                                  publish() call's audit trail). Null for the scheduled path.
     * @param  array<int, int>|null  $customerIds  Internal customer ids to compute for. Null (the
     *                                              default, and what both real callers —
     *                                              ManuscriptGenerationTaskType::run() and
     *                                              ManuscriptController::calculate() — pass) means
     *                                              every customer in the tenant, matching
     *                                              manuscript:calculate's existing scope exactly.
     *                                              Exists as an explicit parameter (rather than
     *                                              tests reaching for a global scope/filter) so
     *                                              tests can exercise real multi-chunk/partial-
     *                                              failure behavior against a small, controlled set
     *                                              instead of this tenant's full ~549-customer
     *                                              production-mirroring dev dataset.
     */
    public function dispatch(string $period, ?ScheduledTask $scheduledTask, bool $autoPublish, ?int $actingUserId = null, ?array $customerIds = null): CommandRun
    {
        $tenantId = (string) (tenant()?->getTenantKey() ?? '');
        $command = 'manuscript:calculate';

        try {
            $commandRun = CommandRun::create([
                'command' => $command,
                'period' => $period,
                'ran_at' => Carbon::now(),
                'metadata' => [
                    'tenant' => $tenantId,
                    'trigger' => $scheduledTask ? 'scheduled' : 'manual',
                ],
                'status' => 'queued',
                // Deliberately NOT initialized to [] here: Laravel's 'array'
                // cast can't distinguish an empty PHP array from an empty PHP
                // object, so [] would be written as JSON `[]` (an array).
                // ComputeManuscriptChunkJob's per-chunk merge then does
                // `coalesce(computed_result, '{}'::jsonb) || chunk_payload`,
                // and Postgres jsonb `||` between an ARRAY and an OBJECT
                // concatenates them as array elements instead of merging keys
                // — silently producing `[{"chunk_0": {...}}]` instead of
                // `{"chunk_0": {...}}`, which then makes every "chunk_N" key
                // invisible to aggregateComputedResult()'s foreach. Leaving
                // this column NULL lets that same COALESCE correctly seed it
                // as a real jsonb OBJECT on the first chunk's merge instead.
                'scheduled_task_id' => $scheduledTask?->id,
            ]);
        } catch (QueryException $e) {
            // idx_command_runs_period_inflight (see that migration's doc
            // comment) is a partial unique index on (command, period) WHERE
            // status IN ('queued', 'pending_review') — the atomic backstop
            // against two independent in-flight runs for the same period
            // (the scheduled tick racing a manual "Run Now" click, or a
            // rapid double-click on that same button). A plain PHP
            // existence check here would have a TOCTOU race window between
            // the check and this INSERT; the DB constraint is what actually
            // makes this safe under real concurrency. SQLSTATE 23505 is
            // Postgres's unique_violation.
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'period' => ["A manuscript calculation for {$period} is already running or awaiting review. Wait for it to finish, or review/publish it, before starting another."],
                ]);
            }

            throw $e;
        }

        $customerIds ??= Customer::query()->orderBy('id')->pluck('id')->all();
        $chunkSize = max(1, (int) config('scheduled_tasks.manuscript_generation.chunk_size', 250));
        $chunks = array_chunk($customerIds, $chunkSize);

        $jobs = [];

        foreach ($chunks as $index => $ids) {
            $jobs[] = new ComputeManuscriptChunkJob($commandRun->id, $period, $ids, $index);
        }

        $commandRunId = $commandRun->id;

        // then()/catch() closures are queued (and therefore serialized) just
        // like any other batch completion callback, so they deliberately
        // capture only primitive values and resolve a fresh service instance
        // via app() at execution time rather than closing over $this — see
        // App\Jobs\ComputeManuscriptChunkJob's class doc for the matching
        // "this always runs under the right tenant's re-initialized tenancy"
        // guarantee (Stancl's QueueTenancyBootstrapper), which applies to
        // these batch callback jobs exactly the same way it does to the
        // chunk jobs themselves.
        $batch = Bus::batch($jobs)
            ->name("manuscript_generation:{$tenantId}:{$period}:{$commandRunId}")
            ->allowFailures()
            ->then(function (Batch $batch) use ($commandRunId, $autoPublish, $actingUserId): void {
                app(self::class)->handleBatchSucceeded($commandRunId, $autoPublish, $actingUserId);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($commandRunId): void {
                app(self::class)->handleBatchFailed($commandRunId, $e);
            })
            ->dispatch();

        $commandRun->update(['batch_id' => $batch->id]);

        return $commandRun->fresh();
    }

    /**
     * then() callback body — fires only once EVERY chunk in the batch
     * succeeded (task-scheduler.md section 4.1). Aggregates the durable
     * per-chunk results into a final customers/summary shape and either
     * publishes immediately (manual trigger) or stops at 'pending_review'
     * (scheduled trigger) for an admin to review.
     */
    public function handleBatchSucceeded(int $commandRunId, bool $autoPublish, ?int $actingUserId): void
    {
        $commandRun = CommandRun::find($commandRunId);

        if (! $commandRun) {
            return;
        }

        $this->aggregateComputedResult($commandRun);

        if ($autoPublish) {
            // publish() can now legitimately refuse (see its own doc
            // comment: a more recently-dispatched run for this period was
            // already published — a defense-in-depth guard that should be
            // rare given dispatch()'s own in-flight uniqueness check, but
            // must never leave this run silently stuck at whatever
            // aggregateComputedResult() left its status as. Surfaced the
            // same way handleBatchFailed() surfaces a whole-chunk failure:
            // 'failed', with the reason recorded in metadata, rather than
            // an uncaught exception disappearing into the queue's failed
            // jobs table with no visibility on the Command Runs page.
            try {
                $this->publish($commandRun->fresh(), $actingUserId);
            } catch (ValidationException $e) {
                $commandRun->refresh()->update([
                    'status' => 'failed',
                    'metadata' => [...($commandRun->metadata ?? []), 'batch_failure' => collect($e->errors())->flatten()->implode(' ')],
                ]);

                report($e);
            }
        } else {
            $commandRun->update(['status' => 'pending_review']);
        }
    }

    /**
     * catch() callback body — fires the first time ANY chunk job in the
     * batch throws outright (a whole chunk crashing, not a per-customer
     * error already tolerated inside a successful chunk). Per section
     * 4.1: never silently auto-transition to 'pending_review' when this
     * happens — a manuscript period built from incomplete chunk data would
     * silently under-report real customers. Whatever chunks DID complete
     * are still aggregated (for visibility/debugging), but the run is
     * surfaced as 'failed' either way.
     */
    public function handleBatchFailed(int $commandRunId, Throwable $e): void
    {
        $commandRun = CommandRun::find($commandRunId);

        if (! $commandRun) {
            return;
        }

        $this->aggregateComputedResult($commandRun);

        $commandRun->refresh();
        $commandRun->update([
            'status' => 'failed',
            'metadata' => [...($commandRun->metadata ?? []), 'batch_failure' => $e->getMessage()],
        ]);

        report($e);
    }

    /**
     * Commits the EXACT computed_result already stored on $commandRun to
     * the live `manuscripts` table — never a fresh recomputation. This is
     * what guarantees "whatever an admin sees in the preview must be
     * exactly what gets published" (section 4): even if payments changed
     * between compute and this call, only the payment ids that were part
     * of the ORIGINAL computed result get marked processed, and only the
     * ORIGINAL computed arrears/credit/total_bill get written.
     *
     * EXCEPT for a customer whose manuscript row was modified out-of-band
     * after this run started computing (`manuscripts.updated_at >
     * $commandRun->ran_at`) — e.g. by
     * App\Services\CustomerManuscriptRecalculationService::recalculateOne(),
     * which App\Services\ArrearsAdjustmentService::approve() calls directly,
     * with no preview/publish gate of its own. Blindly overwriting that row
     * with this run's stale snapshot would silently revert a real,
     * already-applied correction (and re-stamp payments/adjustments the
     * fresher recalculation had already accounted for differently) with no
     * error, no warning, and no trace — a live bug this exact scenario
     * reproduced and confirmed (2026-08 audit). Such a customer is skipped
     * entirely (manuscript left untouched, none of their payment/adjustment
     * ids re-stamped) rather than blocking the rest of the run; skipped
     * customer ids are recorded in `metadata.skipped_stale_customers` so
     * it's visible, not silent — an admin can re-run the calculation to
     * pick that customer up fresh.
     *
     * Idempotent: publishing an already-published run is a no-op, so a
     * double-click (or the manual-trigger auto-publish racing an admin
     * click on a since-scheduled row) can't double-process payments.
     */
    public function publish(CommandRun $commandRun, ?int $actingUserId = null): CommandRun
    {
        if ($commandRun->status === 'published') {
            return $commandRun;
        }

        // Refuse to publish over a MORE RECENTLY-DISPATCHED run for the same
        // period that has already been published — the stale-preview
        // overwrite scenario: e.g. a scheduled run (dispatched at 2am) sits
        // in pending_review while an admin's manual "Run Now" trigger
        // (dispatched later, at 9am, over fresher payment data) auto-publishes
        // first. If the admin then reviews and clicks Publish on the OLDER
        // 2am preview, that must not silently overwrite the already-live,
        // more-current 9am numbers.
        //
        // "More recently dispatched" is decided by `id` (bigserial,
        // monotonically increasing in dispatch order) rather than any
        // timestamp — immune to clock skew/ties and exactly matches
        // insertion order, which IS dispatch order here since CommandRun
        // rows are only ever created by dispatch(). A published run with a
        // HIGHER id than $commandRun was dispatched after it and is
        // therefore the current source of truth for this period; a
        // published run with a LOWER id is an earlier, superseded run that
        // $commandRun's own (later) publish is allowed to legitimately
        // overwrite (e.g. an admin re-running a period after a data fix).
        $newerPublished = CommandRun::query()
            ->where('command', $commandRun->command)
            ->where('period', $commandRun->period)
            ->where('status', 'published')
            ->where('id', '>', $commandRun->id)
            ->exists();

        if ($newerPublished) {
            throw ValidationException::withMessages([
                'period' => ["Manuscript period {$commandRun->period} was already published by a more recent run. This preview is stale — discard it and re-run/re-review instead of publishing."],
            ]);
        }

        $customers = $commandRun->computed_result['customers'] ?? [];
        $skippedStaleCustomers = [];

        DB::transaction(function () use ($commandRun, $customers, $actingUserId, &$skippedStaleCustomers): void {
            foreach ($customers as $customerId => $entry) {
                $existing = Manuscript::query()
                    ->where('customer_id', (int) $customerId)
                    ->where('period', $commandRun->period)
                    ->first();

                // >=, not >: command_runs.ran_at and manuscripts.updated_at
                // are both second-precision (timestamp(0)) columns, so a
                // race landing in the same wall-clock second — the norm in
                // an automated test, and possible in production too — would
                // otherwise compare equal and slip past a strict >. Ties bias
                // toward "treat as stale and skip" rather than risk silently
                // overwriting a fresher out-of-band correction; the only
                // false-positive cost is an admin occasionally needing to
                // re-run to pick up a customer skipped by coincidence, which
                // is far cheaper than reverting a real correction unnoticed.
                if ($existing && $existing->updated_at->greaterThanOrEqualTo($commandRun->ran_at)) {
                    $skippedStaleCustomers[] = (int) $customerId;

                    continue;
                }

                Manuscript::query()
                    ->firstOrNew(['customer_id' => (int) $customerId, 'period' => $commandRun->period])
                    ->fill($entry['attributes'] ?? [])
                    ->save();

                $paymentIds = $entry['processed_payment_ids'] ?? [];

                if ($paymentIds !== []) {
                    // Stamps both processed_at (display/audit timestamp) and
                    // processed_period (the period-eligibility marker — see
                    // App\Services\ManuscriptCalculator's class doc). The
                    // guard is now "not already attributed to a DIFFERENT
                    // period" rather than whereNull('processed_at'): a
                    // republish of this exact commandRun/period (see
                    // publish()'s already-published no-op guard above) or a
                    // second scheduled/manual run computed for this same
                    // period must be able to re-stamp its own payments
                    // without that being mistaken for "already claimed by
                    // someone else."
                    Payment::query()
                        ->whereIn('id', $paymentIds)
                        ->where(fn ($query) => $query->whereNull('processed_period')->orWhere('processed_period', $commandRun->period))
                        ->update(['processed_at' => Carbon::now(), 'processed_period' => $commandRun->period]);
                }

                // Same re-stampable-on-republish guard, applied to arrears
                // adjustments consumed by this customer's computed result —
                // see App\Services\ManuscriptCalculator's class doc for why
                // an eligible adjustment is consumed even in a frozen branch.
                $adjustmentIds = $entry['processed_adjustment_ids'] ?? [];

                if ($adjustmentIds !== []) {
                    ArrearsAdjustment::query()
                        ->whereIn('id', $adjustmentIds)
                        ->where(fn ($query) => $query->whereNull('processed_period')->orWhere('processed_period', $commandRun->period))
                        ->update(['processed_at' => Carbon::now(), 'processed_period' => $commandRun->period]);
                }
            }

            $commandRun->update([
                'status' => 'published',
                'published_at' => Carbon::now(),
                'published_by' => $actingUserId,
                'metadata' => [...($commandRun->metadata ?? []), 'skipped_stale_customers' => $skippedStaleCustomers],
            ]);
        });

        $this->manuscripts->forgetSummaryCache($commandRun->period);

        return $commandRun->fresh();
    }

    /**
     * Flattens every "chunk_N" key currently on computed_result into a
     * single customers map plus an aggregate summary (mirroring
     * ManuscriptCalculate::runForEveryCustomer()'s $stats shape so
     * CommandRuns.tsx's existing metadata-based outcome badge/summary
     * formatting keeps working unchanged for scheduler-driven runs too —
     * the summary is ALSO folded into `metadata` for exactly that reason).
     * Runs once, after all chunks are done (called only from the then()/
     * catch() bodies above), so — unlike the per-chunk merge in
     * ComputeManuscriptChunkJob — this can safely read-then-write without
     * a concurrency race.
     */
    private function aggregateComputedResult(CommandRun $commandRun): void
    {
        $commandRun->refresh();
        $raw = $commandRun->computed_result ?? [];

        $customers = [];
        $customersProcessed = 0;
        $frozenCustomers = 0;
        $totalArrearsSum = '0.00';
        $totalCreditSum = '0.00';
        $totalBillSum = '0.00';
        $errors = 0;
        $errorDetails = [];

        foreach ($raw as $key => $chunk) {
            if (! is_string($key) || ! str_starts_with($key, 'chunk_') || ! is_array($chunk)) {
                continue;
            }

            $customers += $chunk['customers'] ?? [];
            $stats = $chunk['stats'] ?? [];

            $customersProcessed += (int) ($stats['customers_processed'] ?? 0);
            $frozenCustomers += (int) ($stats['frozen_customers'] ?? 0);
            $totalArrearsSum = bcadd($totalArrearsSum, (string) ($stats['total_arrears_sum'] ?? '0.00'), 2);
            $totalCreditSum = bcadd($totalCreditSum, (string) ($stats['total_credit_sum'] ?? '0.00'), 2);
            $totalBillSum = bcadd($totalBillSum, (string) ($stats['total_bill_sum'] ?? '0.00'), 2);
            $errors += (int) ($stats['errors'] ?? 0);
            $errorDetails = [...$errorDetails, ...($stats['error_details'] ?? [])];
        }

        $summary = [
            'customers_processed' => $customersProcessed,
            'frozen_customers' => $frozenCustomers,
            'total_arrears_sum' => (float) $totalArrearsSum,
            'total_credit_sum' => (float) $totalCreditSum,
            'total_bill_sum' => (float) $totalBillSum,
            'errors' => $errors,
            'error_details' => $errorDetails,
        ];

        $commandRun->update([
            'computed_result' => [
                'customers' => $customers,
                'summary' => $summary,
            ],
            'metadata' => [...($commandRun->metadata ?? []), ...$summary],
        ]);
    }

    /**
     * True when $e wraps a Postgres unique_violation (SQLSTATE 23505) —
     * used by dispatch() to translate idx_command_runs_period_inflight's
     * constraint violation into a friendly ValidationException rather than
     * letting a raw QueryException bubble up. Checks the underlying PDO
     * exception's SQLSTATE rather than matching on message text/constraint
     * name, which is fragile across Postgres versions/locales.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $previous = $e->getPrevious();

        $sqlState = $previous instanceof \PDOException ? $previous->getCode() : $e->getCode();

        return $sqlState === '23505';
    }
}
