<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenditures: one cash-out point, month close, spending cap
     * (docs/plans/expenditures-and-spending-controls.md).
     *
     * - expenditures.voucher_id / source — every new expenditure comes from a
     *   paid or settled bon (`voucher`); a correction of a paid bon posts a
     *   negative `adjustment` row in the current month; everything recorded
     *   directly before this change is `legacy` (read-only except void).
     *   The FK restricts deletes: a paid bon can never lose its expenditure.
     * - status / void_* — Void + reason replaces Delete. Voided rows stay,
     *   excluded from every total. edit_reason travels with each edit into
     *   the audit log.
     * - relates_to_period — a late item (an advance settled next month, a
     *   correction) lands in the current month, pointing back at the month
     *   it belongs to.
     * - vouchers.expenditure_id now restricts deletes (was nullOnDelete,
     *   which orphaned a paid bon). vouchers.from_collections / remittance_id
     *   — "already spent from my collections": once approved, the bon is
     *   paid at that agent's handover. local_uuid makes the mobile bon
     *   request idempotent.
     * - companies.monthly_spending_cap (NULL = no cap) and the daily bon
     *   limit on by default at 100,000 (E8).
     * - remittances.bons_total — bons paid out of the collector's cash at
     *   handover; the cash expected in the drawer is expected_cash − bons_total.
     * - expenditure_months — one row per closed (or reopened) month with the
     *   totals snapshot taken when it closed (E6).
     */
    public function up(): void
    {
        Schema::table('expenditures', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->restrictOnDelete();
            $table->string('source', 20)->default('legacy');
            $table->string('status', 10)->default('active');
            $table->string('relates_to_period', 7)->nullable();
            $table->foreignId('corrects_expenditure_id')->nullable()->constrained('expenditures')->restrictOnDelete();
            $table->string('edit_reason', 500)->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestampTz('voided_at')->nullable();

            $table->index('voucher_id', 'idx_expenditures_voucher');
            $table->index(['status', 'spent_at'], 'idx_expenditures_status_spent');
        });

        DB::statement("ALTER TABLE expenditures ADD CONSTRAINT expenditures_source_check CHECK (source IN ('voucher', 'legacy', 'adjustment'))");
        DB::statement("ALTER TABLE expenditures ADD CONSTRAINT expenditures_status_check CHECK (status IN ('active', 'void'))");
        DB::statement('ALTER TABLE expenditures ADD CONSTRAINT expenditures_voided_by_foreign FOREIGN KEY (voided_by) REFERENCES public.users(id)');

        // Corrections are negative rows; everything else stays positive.
        DB::statement('ALTER TABLE expenditures DROP CONSTRAINT IF EXISTS expenditures_amount_positive');
        DB::statement("ALTER TABLE expenditures ADD CONSTRAINT expenditures_amount_sign CHECK ((source = 'adjustment' AND amount < 0) OR (source <> 'adjustment' AND amount > 0))");

        // Bon-paid rows written before this change: link them to their bon.
        DB::statement("UPDATE expenditures SET voucher_id = v.id, source = 'voucher' FROM vouchers v WHERE v.expenditure_id = expenditures.id");

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropForeign(['expenditure_id']);
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreign('expenditure_id')->references('id')->on('expenditures')->restrictOnDelete();
            $table->boolean('from_collections')->default(false);
            $table->foreignId('remittance_id')->nullable()->constrained('remittances')->restrictOnDelete();
            $table->uuid('local_uuid')->nullable()->unique();
            $table->string('recorded_by_device')->nullable();
            $table->string('receipt_path')->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('monthly_spending_cap', 12, 2)->nullable();
            $table->decimal('voucher_daily_limit', 12, 2)->nullable()->default(100000)->change();
        });

        DB::table('companies')->whereNull('voucher_daily_limit')->update(['voucher_daily_limit' => 100000]);

        Schema::table('remittances', function (Blueprint $table) {
            $table->decimal('bons_total', 12, 2)->default(0);
        });

        Schema::create('expenditure_months', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('period', 7)->unique();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        foreach (['closed_by', 'reopened_by'] as $column) {
            DB::statement("ALTER TABLE expenditure_months ADD CONSTRAINT expenditure_months_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenditure_months');

        Schema::table('remittances', function (Blueprint $table) {
            $table->dropColumn('bons_total');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('monthly_spending_cap');
            $table->decimal('voucher_daily_limit', 12, 2)->nullable()->default(null)->change();
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropForeign(['expenditure_id']);
            $table->dropForeign(['remittance_id']);
            $table->dropColumn(['from_collections', 'remittance_id', 'local_uuid', 'recorded_by_device', 'receipt_path']);
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreign('expenditure_id')->references('id')->on('expenditures')->nullOnDelete();
        });

        DB::statement('ALTER TABLE expenditures DROP CONSTRAINT IF EXISTS expenditures_amount_sign');
        DB::statement('ALTER TABLE expenditures DROP CONSTRAINT IF EXISTS expenditures_source_check');
        DB::statement('ALTER TABLE expenditures DROP CONSTRAINT IF EXISTS expenditures_status_check');
        DB::statement('ALTER TABLE expenditures DROP CONSTRAINT IF EXISTS expenditures_voided_by_foreign');

        Schema::table('expenditures', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
            $table->dropForeign(['corrects_expenditure_id']);
            $table->dropIndex('idx_expenditures_voucher');
            $table->dropIndex('idx_expenditures_status_spent');
            $table->dropColumn(['voucher_id', 'source', 'status', 'relates_to_period', 'corrects_expenditure_id', 'edit_reason', 'void_reason', 'voided_by', 'voided_at']);
        });

        DB::statement('ALTER TABLE expenditures ADD CONSTRAINT expenditures_amount_positive CHECK (amount > 0)');
    }
};
