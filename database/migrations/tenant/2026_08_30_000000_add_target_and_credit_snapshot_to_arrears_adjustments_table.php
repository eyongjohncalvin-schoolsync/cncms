<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit-correction support for the Arrears Adjustment maker-checker
     * feature — see `.claude/skills/cncms-context/references/arrears-adjustment.md`'s
     * 2026-08-30 addendum (the "Adjust credit / Clear credit" fallback), and
     * the 2026-08 `swecom` incident that motivated it: an approved arrears
     * adjustment against an IMPORTED-BASELINE manuscript row
     * (`command_run_id IS NULL`, no v2 payment history behind it) triggered a
     * from-scratch `ManuscriptCalculator` recompute that re-counted every
     * historical v1 payment as fresh August income and fabricated a huge
     * bogus `credit`. The `credit` column now needs its own bounded,
     * maker-checker correction path.
     *
     * Additive and backfill-safe:
     *
     * - `target` — `'arrears'` (default, every existing row's behavior
     *   unchanged) or `'credit'`. A plain string, NOT a DB enum/CHECK: the
     *   allowed set is enforced at the FormRequest/DTO layer
     *   (StoreArrearsAdjustmentRequest), same as this feature's design doc
     *   describes `reason_category` as "a plain string column, no DB check
     *   constraint".
     * - `credit_snapshot` — nullable decimal(12,2) alongside the existing
     *   `arrears_snapshot`, so `ArrearsAdjustmentService::approve()`'s
     *   approval-time staleness re-check can cover credit drift too when
     *   `target = 'credit'` (it currently only re-checks `arrears_snapshot`).
     *   Null for every historical row and for `target = 'arrears'` requests.
     *
     * `reason_category` was created via `$table->enum()`, which on PostgreSQL
     * is a varchar plus a `CHECK (... IN (...))` constraint. The new
     * credit-correction categories (`credit_correction`, `duplicate_credit`,
     * `migration_credit_error`) must be accepted alongside the original
     * arrears categories; rather than juggle the CHECK constraint's value
     * list, drop it — the app already validates the allowed set at the
     * request layer, so the DB-level CHECK is redundant scaffolding. This
     * brings `reason_category` in line with what the design doc already
     * describes it as.
     */
    public function up(): void
    {
        Schema::table('arrears_adjustments', function (Blueprint $table) {
            $table->string('target')->default('arrears')->after('direction');
            $table->decimal('credit_snapshot', 12, 2)->nullable()->after('arrears_snapshot');
        });

        // Guarded — the constraint name is Laravel's default
        // (`{table}_{column}_check`); IF EXISTS keeps this a no-op on any
        // schema where it was already removed or never created.
        DB::statement('ALTER TABLE arrears_adjustments DROP CONSTRAINT IF EXISTS arrears_adjustments_reason_category_check');
    }

    public function down(): void
    {
        Schema::table('arrears_adjustments', function (Blueprint $table) {
            $table->dropColumn(['target', 'credit_snapshot']);
        });
    }
};
