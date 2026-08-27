<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extends `command_runs` (previously a plain manuscript:calculate audit
     * log) to also be the execution history for the generic task scheduler
     * (task-scheduler.md sections 2 and 4):
     *
     * - `status`: 'queued' (batch dispatched, chunks still running) ->
     *   'pending_review' (all chunks finished cleanly; a *scheduled*
     *   manuscript_generation run stops here awaiting an admin's Publish) ->
     *   'published' (committed to the live `manuscripts` table — either
     *   because an admin clicked Publish, or immediately/automatically for
     *   a manual "run now" trigger, which has no review gate per section
     *   4's "scheduled path only" rule) or 'failed' (a whole chunk job
     *   threw — batch-level failure, not a per-customer error already
     *   tolerated inside a chunk — surfaced instead of silently advancing
     *   to pending_review, per section 4.1).
     *   Existing historical rows (all logged by the old synchronous,
     *   immediate-commit manuscript:calculate command) default to
     *   'published' — they already committed live data at run time, which
     *   is exactly what that status means.
     * - `computed_result`: the durable, per-customer computed result set
     *   (arrears/credit/total_bill/frozen-status per customer, plus which
     *   payment ids each customer's calculation consumed) that Publish
     *   commits VERBATIM to `manuscripts` — never a fresh recomputation.
     *   This is what makes "what the admin previewed is exactly what gets
     *   published" possible even if live payment data changes in between.
     * - `scheduled_task_id`: which scheduled_tasks row triggered this run
     *   (null for a manual "run now" trigger).
     * - `batch_id`: the Bus::batch() `job_batches.id` for this run, so the
     *   Settings UI (and this migration's own author, debugging) can join
     *   across to Laravel's free built-in progress tracking
     *   (total_jobs/pending_jobs/failed_jobs) without duplicating it here.
     * - `published_at`/`published_by`: audit trail for the Publish action.
     *   `published_by` is a cross-schema FK to the central public.users
     *   table (same pattern as payment_verifications.verified_by).
     */
    public function up(): void
    {
        Schema::table('command_runs', function (Blueprint $table) {
            $table->string('status', 20)->default('published')->after('metadata');
            $table->jsonb('computed_result')->nullable()->after('status');
            $table->foreignId('scheduled_task_id')->nullable()->after('computed_result')
                ->constrained('scheduled_tasks')->nullOnDelete();
            $table->string('batch_id', 36)->nullable()->after('scheduled_task_id');
            $table->timestampTz('published_at')->nullable()->after('batch_id');
            // published_by is a cross-schema FK to the central public.users table — see note below.
            $table->unsignedBigInteger('published_by')->nullable()->after('published_at');

            $table->index('status', 'idx_command_runs_status');
            $table->index('published_by');
        });

        // Cross-schema FK: tenant schemas run with a single-schema search_path, so
        // `public` is not implicitly searched — same reasoning as
        // payment_verifications.verified_by (see that migration's note).
        DB::statement('ALTER TABLE command_runs ADD CONSTRAINT command_runs_published_by_foreign FOREIGN KEY (published_by) REFERENCES public.users(id)');
    }

    public function down(): void
    {
        Schema::table('command_runs', function (Blueprint $table) {
            DB::statement('ALTER TABLE command_runs DROP CONSTRAINT IF EXISTS command_runs_published_by_foreign');
            $table->dropConstrainedForeignId('scheduled_task_id');
            $table->dropColumn(['status', 'computed_result', 'batch_id', 'published_at', 'published_by']);
        });
    }
};
