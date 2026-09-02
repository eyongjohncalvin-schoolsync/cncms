<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asynchronous (queued) bill generation — the owner's 2026-08-30 ask to
     * move the synchronous `GET /manuscripts/bills` (which had to raise
     * memory_limit to 1024M / time to 180s inside a web request) into a
     * background Bus::batch() job that writes downloadable PDF artifacts.
     * See App\Services\BillBatchService / App\Jobs\GenerateBillsJob.
     *
     * `bill_batches` — one row per generation run: the period it was
     * generated for, the density/template it was rendered at (snapshotted
     * from `companies` at dispatch time so a later Settings change never
     * retroactively mislabels a finished artifact), the filters it was
     * scoped to (json — usually just `{"period": "..."}`, but zone_uuid/
     * status/search are honoured too), a running status, and the
     * Illuminate\Bus\Batch id for progress polling (job_batches, central
     * schema — see App\Support\ResolvesCommandRunBatchProgress).
     *
     * status: queued -> processing -> (completed | partial | failed)
     *   - completed: the bulk PDF and every per-zone PDF were written.
     *   - partial:   the bulk PDF plus at least one zone PDF exist, but one
     *                or more zone jobs failed. The finished files are still
     *                downloadable.
     *   - failed:    the bulk PDF is missing, or no zone PDF was produced.
     *
     * `bill_batch_files` — the artifacts. One row per per-zone PDF
     * (zone_id set), one row for the single all-zones bulk PDF (zone_id
     * NULL, kind='bulk'), and one row for the convenience ZIP of the
     * per-zone PDFs (zone_id NULL, kind='zip'). zone_name is denormalized
     * so a download label stays stable if a zone is later renamed/deleted.
     *
     * `generated_by` is a cross-schema FK into public.users — the tenant
     * search_path does not implicitly include `public`, so it is added via
     * a raw DB::statement, exactly like command_runs.published_by and
     * arrears_adjustments.requested_by.
     */
    public function up(): void
    {
        Schema::create('bill_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));

            $table->string('period', 7);
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('density')->default(1);
            $table->string('template', 32)->default('classic');
            $table->json('filters')->nullable();
            $table->unsignedInteger('total_bills')->default(0);
            // How many per-zone PDFs this run is expected to produce — the
            // finalizer compares this against the zone files actually written
            // to decide completed vs partial (see App\Services\BillBatchService).
            $table->unsignedInteger('total_zones')->default(0);

            $table->unsignedBigInteger('generated_by')->nullable();
            $table->string('batch_id')->nullable();
            $table->text('error_message')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['period', 'created_at'], 'idx_bill_batches_period');
            $table->index('generated_by', 'idx_bill_batches_generated_by');
        });

        DB::statement('ALTER TABLE bill_batches ADD CONSTRAINT bill_batches_generated_by_foreign FOREIGN KEY (generated_by) REFERENCES public.users(id) ON DELETE SET NULL');

        Schema::create('bill_batch_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));

            $table->foreignId('bill_batch_id')->constrained('bill_batches')->cascadeOnDelete();
            // NULL zone_id = the single bulk all-zones PDF or the ZIP; a real
            // zone_id = that zone's PDF. nullOnDelete so deleting a zone never
            // orphans a FK but the denormalized zone_name still labels the row.
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->string('zone_name')->nullable();
            $table->string('kind', 16)->default('zone'); // zone | bulk | zip

            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->unsignedInteger('bill_count')->default(0);
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->timestamps();

            $table->index('bill_batch_id', 'idx_bill_batch_files_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_batch_files');

        if (Schema::hasTable('bill_batches')) {
            DB::statement('ALTER TABLE bill_batches DROP CONSTRAINT IF EXISTS bill_batches_generated_by_foreign');
        }

        Schema::dropIfExists('bill_batches');
    }
};
