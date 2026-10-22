<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installation fees, phase 1 (docs/plans/installation-and-promotions.md) —
 * one-off amounts a customer owes outside the monthly bill. Only
 * `installation` exists today; relocation / re-installation reuse the same
 * shape later (plan: "Out of scope / later").
 *
 * The balance is NEVER stored: it is `amount` minus the verified
 * installation payments linked through payments.customer_charge_id (D2:
 * instalments). It is never part of total_bill / arrears and never a reason
 * to disconnect (D1).
 *
 * A waiver is maker-checker (D8): requested by one person (waiver_status =
 * pending), approved or rejected by another. On approval the remaining
 * balance is recorded in `waived_amount` and the charge becomes `waived`.
 * `refunded` is reserved for phase 2's "Refund installation" (D9).
 *
 * Phase 2 adds the offer link (installation_offer_id) next to
 * `standard_amount`, which is the offer's fee when one applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('kind', 30)->default('installation');
            $table->decimal('amount', 12, 2);
            $table->decimal('standard_amount', 12, 2)->nullable();
            $table->string('status', 20)->default('open');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();

            $table->string('waiver_status', 20)->nullable();
            $table->string('waiver_reason', 500)->nullable();
            $table->unsignedBigInteger('waiver_requested_by')->nullable();
            $table->timestampTz('waiver_requested_at')->nullable();
            $table->unsignedBigInteger('waiver_decided_by')->nullable();
            $table->timestampTz('waiver_decided_at')->nullable();
            $table->string('waiver_rejection_reason', 500)->nullable();
            $table->decimal('waived_amount', 12, 2)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['customer_id', 'kind', 'status'], 'idx_customer_charges_customer_kind_status');
            $table->index('waiver_status', 'idx_customer_charges_waiver_status');
        });

        DB::statement("ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_kind_check CHECK (kind IN ('installation'))");
        DB::statement("ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_status_check CHECK (status IN ('open', 'paid', 'waived', 'refunded'))");
        DB::statement("ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_waiver_status_check CHECK (waiver_status IS NULL OR waiver_status IN ('pending', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_amount_positive CHECK (amount > 0)');

        foreach (['requested_by', 'approved_by', 'waiver_requested_by', 'waiver_decided_by'] as $column) {
            DB::statement("ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_charges');
    }
};
