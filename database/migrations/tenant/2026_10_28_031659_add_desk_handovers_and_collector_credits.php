<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money must balance at every level (owner, 2026-10-28), wave 2:
 *
 *  - remittances.at_desk — cash taken at the desk by someone who holds a
 *    till goes straight into it: a handover made and received at once, so
 *    the till and its day close see it (never anyone's outstanding cash).
 *  - remittances.credit_remaining — cash handed in above what was expected
 *    (a payment not yet synced, a mistake) is the collector's credit: it
 *    covers their next shortages instead of becoming a false one
 *    (remittances.shortage_covered: how much of a shortage it covered).
 *  - payment_reversal_requests — a collector asks the office to reverse a
 *    payment; the office decides in the Reverse dialog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remittances', function (Blueprint $table) {
            $table->boolean('at_desk')->default(false);
            $table->decimal('credit_remaining', 12, 2)->nullable();
            $table->decimal('shortage_covered', 12, 2)->default(0);
        });

        // Overages already handed in become the collector's credit.
        DB::statement('UPDATE remittances SET credit_remaining = cash_variance WHERE status = \'received\' AND cash_variance > 0');

        Schema::create('payment_reversal_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->unsignedBigInteger('requested_by');
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('status', 'idx_reversal_requests_status');
        });

        foreach (['requested_by', 'decided_by'] as $column) {
            DB::statement("ALTER TABLE payment_reversal_requests ADD CONSTRAINT payment_reversal_requests_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }

        // One open request per payment.
        DB::statement("CREATE UNIQUE INDEX uq_reversal_requests_open ON payment_reversal_requests (payment_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversal_requests');

        Schema::table('remittances', function (Blueprint $table) {
            $table->dropColumn(['at_desk', 'credit_remaining', 'shortage_covered']);
        });
    }
};
