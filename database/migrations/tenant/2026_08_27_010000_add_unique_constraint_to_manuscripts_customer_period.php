<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a genuine DB-level uniqueness constraint on manuscripts(customer_id,
 * period) — until now only the non-unique idx_manuscripts_customer_period
 * composite index existed (2026_08_19_090533_create_manuscripts_table.php),
 * so nothing at the database layer actually prevented two manuscript rows
 * for the same customer/period from ever coexisting; every write path just
 * happens to use firstOrNew()/upsert-by-(customer_id, period) semantics
 * today (ManuscriptCalculate::runForEveryCustomer(),
 * ManuscriptGenerationBatchService::publish()). This closes that gap the
 * same way idx_command_runs_period_inflight closes the analogous gap on
 * command_runs: a real constraint the database enforces regardless of
 * which code path writes the row, rather than relying on every caller
 * remembering to upsert correctly forever.
 *
 * Verified against the real data before adding this (2026-08-27): zero
 * duplicate (customer_id, period) rows and zero NULL-period rows exist in
 * any of the 5 currently-provisioned tenant schemas (tenantswecom,
 * tenantmultimedia-digital-cable-network, and three tenantzreg... trial
 * schemas), so this constraint is safe to add outright — no dedup step
 * needed first.
 *
 * A plain unique constraint (not a partial/conditional one) is correct
 * here: unlike idx_command_runs_period_inflight, which deliberately only
 * guards a transient in-flight window (queued/pending_review) and allows
 * many historical published/failed rows per period, EVERY manuscripts row
 * for a given (customer_id, period) is meant to be the single current
 * record for that customer's billing in that period — there is no
 * legitimate reason for two to ever coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->unique(['customer_id', 'period'], 'uq_manuscripts_customer_period');
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropUnique('uq_manuscripts_customer_period');
        });
    }
};
