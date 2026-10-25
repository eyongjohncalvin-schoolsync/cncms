<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compensation for poor service (owner, 2026-10-25):
 *
 *  - A manager may give one customer up to `compensation_months_without_approval`
 *    free months a year on their own (default 1; 0 = everything waits for
 *    approval); beyond that someone who approves decides.
 *  - A whole zone or branch can be compensated at once (an outage): one
 *    compensation_batches row, one free_month_grants request per customer,
 *    and one approval by someone else gives everyone their months.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('compensation_months_without_approval')->default(1);
        });

        Schema::create('compensation_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            // Null = the whole branch.
            $table->foreignId('zone_id')->nullable()->constrained('zones')->restrictOnDelete();
            // Null = every service each customer holds.
            $table->jsonb('service_ids')->nullable();
            $table->unsignedSmallInteger('months');
            $table->string('note', 500);
            $table->string('status', 20);
            $table->unsignedInteger('customers_count')->default(0);
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE compensation_batches ADD CONSTRAINT compensation_batches_status_check CHECK (status IN ('pending_approval', 'approved', 'rejected'))");

        Schema::table('free_month_grants', function (Blueprint $table): void {
            $table->foreignId('compensation_batch_id')->nullable()->constrained('compensation_batches')->restrictOnDelete();
            $table->index('compensation_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('free_month_grants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('compensation_batch_id');
        });

        Schema::dropIfExists('compensation_batches');

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('compensation_months_without_approval');
        });
    }
};
