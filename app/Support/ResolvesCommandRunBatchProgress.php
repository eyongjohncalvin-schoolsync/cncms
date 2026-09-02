<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Shared `job_batches` progress lookup for a `command_runs` row still at
 * 'queued' (Illuminate\Bus\Batch's own auto-created table — total/pending/
 * failed/finished_at). Extracted so the two "watch a run's progress"
 * surfaces read it identically rather than drifting: the many-rows-at-once
 * listing on Settings > Command Runs
 * (App\Http\Controllers\SettingsCommandRunController::index(), the original
 * home of this method) and the new single-run "just-triggered, watch it
 * compute" screen reachable directly from Manuscripts
 * (App\Http\Controllers\ManuscriptController::runReview() — task-scheduler.md's
 * stage 3 addendum).
 */
trait ResolvesCommandRunBatchProgress
{
    /**
     * @param  array<int, string>  $batchIds
     * @return array<string, array{total: int, pending: int, failed: int, finished: bool}>
     */
    private function batchProgress(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        // `job_batches` is Laravel's queue-batch table and lives ONLY in the
        // central schema (database/migrations/0001_01_01_000002_create_jobs_table.php)
        // — never in a tenant schema. While tenancy is initialized the
        // default connection is `tenant` (search_path = the tenant schema),
        // so a bare DB::table('job_batches') resolves to a non-existent
        // relation. Pin the lookup to the central connection, which keeps
        // search_path = public. (Latent until now: swecom's real command_runs
        // are all CLI `manuscript:calculate` rows with batch_id = null, so
        // this filter was never non-empty for it in production.)
        return DB::connection(config('tenancy.database.central_connection'))
            ->table('job_batches')
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
