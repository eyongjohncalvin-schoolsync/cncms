<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CommandRun;
use Illuminate\Validation\ValidationException;

/**
 * The "already safely runnable" guard (task-scheduler.md's 2026-08-27
 * addendum: "Manuscript rerun-safety guard"). Stops `manuscript:calculate`
 * from silently repeating an ALREADY-PUBLISHED period — a different failure
 * mode from idx_command_runs_period_inflight (see that migration's doc
 * comment), which only stops two SIMULTANEOUS in-flight runs. Both guards
 * are complementary and must coexist:
 *
 *  - idx_command_runs_period_inflight (a DB partial unique index): two runs
 *    racing RIGHT NOW for the same period — a queued/pending_review
 *    collision.
 *  - This guard (an application-level check, run BEFORE any command_runs row
 *    is even inserted): a run for a period that already finished and
 *    published, being kicked off again — accidentally (a stray re-click,
 *    a cron misfire) or deliberately (a real data-fix rerun) — with no
 *    explicit confirmation.
 *
 * Used identically by both entry points that can trigger
 * manuscript:calculate: App\Services\ManuscriptGenerationBatchService::
 * dispatch() (the web "Run Manuscript Calculation" trigger, via
 * App\Http\Controllers\ManuscriptController::calculate()'s `confirmed_rerun`
 * input, and the scheduled path) and App\Console\Commands\ManuscriptCalculate
 * (the raw CLI command, via its `--force` option) — see each caller's own
 * doc comment for exactly where in its flow this runs. Arithmetic safety for
 * an actual rerun is NOT this guard's job — payments.processed_period
 * already makes a rerun idempotent (see App\Services\ManuscriptCalculator's
 * class doc); this guard is pure process safety, stopping an unintentional
 * repeat before it starts.
 */
class ManuscriptRerunGuard
{
    /**
     * @throws ValidationException when a published manuscript:calculate run
     *                              already exists for $period and $override
     *                              is false.
     */
    public function assertRerunAllowed(string $period, bool $override): void
    {
        if ($override) {
            return;
        }

        $priorRun = CommandRun::query()
            ->where('command', 'manuscript:calculate')
            ->where('period', $period)
            ->where('status', 'published')
            ->latest('id')
            ->first();

        if (! $priorRun) {
            return;
        }

        throw ValidationException::withMessages([
            'period' => [$this->describe($priorRun, $period)],
        ]);
    }

    /**
     * Reads exclusively from fields CommandRun already carries — ran_at,
     * metadata['trigger'] (set by ManuscriptGenerationBatchService::
     * dispatch(); the CLI command sets the same key to 'cli'),
     * scheduled_task_id (as a trigger fallback for older rows that predate
     * the 'trigger' metadata key), and metadata['customers_processed'] (set
     * by both entry points' completion handling) — no new columns invented
     * for this message.
     */
    private function describe(CommandRun $priorRun, string $period): string
    {
        $ranAt = $priorRun->ran_at === null ? 'an earlier time' : $priorRun->ran_at->format('Y-m-d H:i').' UTC';
        $trigger = $priorRun->metadata['trigger'] ?? ($priorRun->scheduled_task_id ? 'scheduled' : 'manual');
        $customersProcessed = $priorRun->metadata['customers_processed'] ?? null;
        $customersPhrase = $customersProcessed === null
            ? 'an unknown number of customers'
            : "{$customersProcessed} customers";

        return "Manuscript period {$period} was already calculated and published on {$ranAt} ({$trigger} run, {$customersPhrase} processed). Confirm the rerun if you really intend to recompute this period.";
    }
}
