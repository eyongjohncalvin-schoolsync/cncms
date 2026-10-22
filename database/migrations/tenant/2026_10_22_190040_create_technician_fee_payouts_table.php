<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Technician motivation fees (D13, docs/plans/installation-and-promotions.md):
 * the reconnection fine belongs to the technician. The collector hands it to
 * the cashier with the day's cash (it is in the remittance); the cashier then
 * pays it out to the named technician. One payout = one cash-book outflow
 * (kind technician_fee_payout, document TF-…) from the cashier's till, and
 * the fee payments it settles point back at it (payments.technician_fee_payout_id)
 * so a fee can never be paid out twice. Pass-through money — no voucher
 * approval chain, never an expense, never income.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_fee_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('number', 20)->unique();
            $table->string('technician_name', 150);
            $table->unsignedBigInteger('technician_user_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->unsignedBigInteger('paid_by');
            $table->timestampTz('paid_at')->useCurrent();
            $table->string('note', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE technician_fee_payouts ADD CONSTRAINT technician_fee_payouts_amount_positive CHECK (amount > 0)');

        foreach (['technician_user_id', 'paid_by'] as $column) {
            DB::statement("ALTER TABLE technician_fee_payouts ADD CONSTRAINT technician_fee_payouts_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('technician_fee_payout_id')->nullable()->constrained('technician_fee_payouts')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_technician_fee_payout_check CHECK (technician_fee_payout_id IS NULL OR purpose = 'technician_fee')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_technician_fee_payout_check');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technician_fee_payout_id');
        });

        Schema::dropIfExists('technician_fee_payouts');
    }
};
