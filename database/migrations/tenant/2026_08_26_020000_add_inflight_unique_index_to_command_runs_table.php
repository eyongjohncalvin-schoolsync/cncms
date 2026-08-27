<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Guards against two independent in-flight runs for the same
     * (command, period) — e.g. the scheduled manuscript_generation tick
     * racing a manual "Run Manuscript Calculation" click, or a rapid
     * double-click on that same button. A partial unique index (rather
     * than a plain PHP existence check in
     * App\Services\ManuscriptGenerationBatchService::dispatch(), which has
     * a TOCTOU race window between the check and the insert) is the only
     * way to make this atomic under real concurrency — Postgres serializes
     * the two competing INSERTs and rejects whichever loses, exactly the
     * same pattern this schema already uses for
     * idx_payments_processed (a partial index, see the payments table
     * migration) rather than a plain unique constraint, since only
     * 'queued'/'pending_review' rows are mutually exclusive — 'published'
     * and 'failed' are terminal and multiple historical rows for the same
     * period are expected and fine (a legitimate re-run-and-republish after
     * a data fix creates a new row once the old one is no longer
     * in-flight).
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX idx_command_runs_period_inflight ON command_runs (command, period) '.
            "WHERE status IN ('queued', 'pending_review')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_command_runs_period_inflight');
    }
};
