<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money can't leave the books silently (owner, 2026-10-28):
 *
 *  - payments.money_taken — false only when the office said "no money was
 *    taken" while rejecting or reversing it. Every other payment stays on
 *    its collector's handover, rejected or not. Payments rejected before
 *    this rule never went into a handover, so they are marked not taken.
 *  - payments.paid_with_payment_id — a correction recorded before the cash
 *    was handed in: its money is the original payment's, which stays on the
 *    handover in its place.
 *  - payments.held_reason — a phone payment the server took but holds for
 *    the office (customer disconnected, transaction ID already used, bank).
 *  - remittance_lines.outcome — a MoMo / Orange / bank line the cashier
 *    could not see at the handover stays owed until it is found later,
 *    repaid in cash, or written off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('money_taken')->default(true);
            $table->foreignId('paid_with_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('held_reason', 255)->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE payments SET money_taken = false
            WHERE verification_status = 'rejected'
              AND field_installation_id IS NULL
              AND NOT EXISTS (SELECT 1 FROM remittance_lines WHERE remittance_lines.payment_id = payments.id)
            SQL);

        Schema::table('remittance_lines', function (Blueprint $table) {
            $table->string('outcome', 20)->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->string('settled_note', 500)->nullable();
        });

        DB::statement('ALTER TABLE remittance_lines ADD CONSTRAINT remittance_lines_settled_by_foreign FOREIGN KEY (settled_by) REFERENCES public.users(id)');

        // Lines already ticked at a handover were found then.
        DB::statement("UPDATE remittance_lines SET outcome = 'found' WHERE wallet_confirmed = true");
    }

    public function down(): void
    {
        Schema::table('remittance_lines', function (Blueprint $table) {
            $table->dropForeign('remittance_lines_settled_by_foreign');
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropColumn(['outcome', 'settled_by', 'settled_at', 'settled_note']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_with_payment_id');
            $table->dropColumn(['money_taken', 'held_reason']);
        });
    }
};
