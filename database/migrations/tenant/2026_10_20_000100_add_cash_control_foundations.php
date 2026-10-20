<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash controls, wave 1 (docs/plans/cash-controls.md):
     *
     * - payments.method / method_reference — HOW the money came in. Cash
     *   lands in the collector's hand and must be remitted; MTN MoMo /
     *   Orange Money land in the company wallet and carry the operator's
     *   transaction ID, which the cashier ticks off against the wallet
     *   statement. Every existing payment is `cash` (the only way money was
     *   taken before this column existed).
     * - companies.* limits — the per-company voucher rules: a single voucher
     *   above voucher_approval_limit, or one that takes the day's payouts
     *   above voucher_daily_limit, needs every investor's approval. NULL =
     *   no limit. remittance_deadline_hours / advance_settlement_days drive
     *   the "cash not handed over" and "advance not justified" alerts.
     * - document_counters — one row-locked counter per (series, year) for
     *   the numbered cash documents (RB receipt books, RM remittances, BC
     *   vouchers, DP deposits, TR transfers), the same FOR UPDATE scheme as
     *   receipt_counters.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 20)->default('cash');
            $table->string('method_reference', 100)->nullable();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash', 'mtn_momo', 'orange_money', 'bank'))");

        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('voucher_approval_limit', 12, 2)->nullable()->default(50000);
            $table->decimal('voucher_daily_limit', 12, 2)->nullable();
            $table->unsignedSmallInteger('remittance_deadline_hours')->default(24);
            $table->unsignedSmallInteger('advance_settlement_days')->default(7);
        });

        Schema::create('document_counters', function (Blueprint $table) {
            $table->string('series', 4);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['series', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['voucher_approval_limit', 'voucher_daily_limit', 'remittance_deadline_hours', 'advance_settlement_days']);
        });

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_method_check');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['method', 'method_reference']);
        });
    }
};
