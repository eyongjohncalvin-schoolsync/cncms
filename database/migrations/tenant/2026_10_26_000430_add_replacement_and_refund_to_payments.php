<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reversed payment whose cash was already handed in (owner, 2026-10-26)
 * is either corrected — the right payment takes its place in the handover
 * (`replaced_by_payment_id`) — or its money goes back to the customer
 * through a refund bon (`refund_voucher_id`). Either way the counted
 * handover and the cash book still add up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('replaced_by_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('refund_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaced_by_payment_id');
            $table->dropConstrainedForeignId('refund_voucher_id');
        });
    }
};
