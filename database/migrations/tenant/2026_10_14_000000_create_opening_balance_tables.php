<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opening balances — onboarding a company whose customer balances only
     * exist on paper. A batch holds one starting position per customer
     * (amount owed, advance as money and/or as prepaid months), entered as
     * a draft, submitted, and approved by someone else (maker-checker, like
     * arrears_adjustments). Approval writes each customer's first
     * manuscript for the month BEFORE go_live_period, which the first
     * manuscript:calculate then carries forward — the same mechanism the
     * one-off manuscript:import-august used for SWECOM.
     *
     * User columns are cross-schema FKs into public.users, added with raw
     * statements exactly like arrears_adjustments (tenant search_path does
     * not include `public`).
     */
    public function up(): void
    {
        Schema::create('opening_balance_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));

            $table->string('go_live_period', 7);
            $table->enum('status', ['draft', 'pending_approval', 'approved'])->default('draft');

            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('status', 'idx_opening_balance_batches_status');
        });

        DB::statement('ALTER TABLE opening_balance_batches ADD CONSTRAINT opening_balance_batches_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE opening_balance_batches ADD CONSTRAINT opening_balance_batches_submitted_by_foreign FOREIGN KEY (submitted_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE opening_balance_batches ADD CONSTRAINT opening_balance_batches_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE opening_balance_batches ADD CONSTRAINT opening_balance_batches_rejected_by_foreign FOREIGN KEY (rejected_by) REFERENCES public.users(id)');

        Schema::create('opening_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_balance_batch_id')->constrained('opening_balance_batches')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            $table->decimal('arrears', 12, 2)->default(0);
            $table->decimal('advance_amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('advance_months')->default(0);
            $table->string('note', 500)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['opening_balance_batch_id', 'customer_id'], 'uq_opening_balance_entries_batch_customer');
            $table->index('customer_id', 'idx_opening_balance_entries_customer');
        });

        Schema::table('manuscripts', function (Blueprint $table) {
            $table->foreignId('opening_balance_batch_id')
                ->nullable()
                ->constrained('opening_balance_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_balance_batch_id');
        });

        Schema::dropIfExists('opening_balance_entries');
        Schema::dropIfExists('opening_balance_batches');
    }
};
