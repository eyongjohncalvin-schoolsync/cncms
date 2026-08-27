<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Arrears Adjustment maker-checker ledger correction — see this
     * feature's design doc (synthesized from ledger/approval/edge-case/UX
     * audits). Mirrors `complaints`' cross-schema FK pattern for
     * `requested_by`/`approved_by`/`second_approved_by` (raw DB::statement,
     * since tenant search_path does not implicitly include `public`) and
     * `payments.processed_at`/`processed_period`'s idempotency mechanism
     * verbatim for the two columns of the same name here — see
     * App\Services\ManuscriptCalculator's class doc for exactly what
     * "eligible for period P" means.
     *
     * `arrears_snapshot` is NOT part of the original synthesized field list —
     * added to make the approval-time staleness re-check (see
     * App\Services\ArrearsAdjustmentService::approve()) a real, structural
     * comparison rather than a vague "re-check the numbers" instruction with
     * nothing concrete to compare against: it captures the customer's
     * total_arrears (from their latest manuscript, or '0.00' if they have
     * none yet) at the moment the request was made, purely so approve() can
     * detect "the arrears figure this was requested against has since
     * changed" and refuse to silently apply over it — mirroring
     * App\Services\ManuscriptGenerationBatchService::publish()'s
     * newer-published-run guard. It never participates in the ledger math
     * itself.
     */
    public function up(): void
    {
        Schema::create('arrears_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('target_period', 7);
            $table->enum('direction', ['decrease', 'increase']);
            $table->decimal('amount', 12, 2);
            $table->enum('reason_category', [
                'legacy_migration_error', 'billing_error', 'goodwill_service_outage',
                'bad_debt_writeoff', 'credit_clawback', 'other',
            ]);
            $table->text('reason_note');

            $table->decimal('arrears_snapshot', 12, 2)->default(0);

            // Cross-schema FKs into public.users — see class doc above.
            $table->unsignedBigInteger('requested_by');
            $table->enum('status', ['pending', 'pending_second_approval', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('second_approved_by')->nullable();
            $table->timestampTz('second_approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('complaint_id')->nullable()->constrained('complaints')->nullOnDelete();

            // Idempotency marker — identical semantics to payments.processed_at/
            // processed_period (App\Services\ManuscriptCalculator's class doc):
            // null-or-matches-target-period is the eligibility window a
            // manuscript calculation run consumes this adjustment under.
            $table->timestampTz('processed_at')->nullable();
            $table->string('processed_period', 7)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_arrears_adjustments_uuid');
            $table->index('customer_id', 'idx_arrears_adjustments_customer');
            $table->index('status', 'idx_arrears_adjustments_status');
            $table->index('target_period', 'idx_arrears_adjustments_target_period');
            // Eligibility-resolution query shape: WHERE status = 'approved' AND
            // target_period = ? AND (processed_period IS NULL OR processed_period = ?).
            $table->index(['status', 'target_period'], 'idx_arrears_adjustments_status_period');
            $table->index('requested_by', 'idx_arrears_adjustments_requested_by');
            $table->index('approved_by', 'idx_arrears_adjustments_approved_by');
            $table->index('complaint_id', 'idx_arrears_adjustments_complaint');
        });

        DB::statement('ALTER TABLE arrears_adjustments ADD CONSTRAINT arrears_adjustments_requested_by_foreign FOREIGN KEY (requested_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE arrears_adjustments ADD CONSTRAINT arrears_adjustments_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE arrears_adjustments ADD CONSTRAINT arrears_adjustments_second_approved_by_foreign FOREIGN KEY (second_approved_by) REFERENCES public.users(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('arrears_adjustments');
    }
};
