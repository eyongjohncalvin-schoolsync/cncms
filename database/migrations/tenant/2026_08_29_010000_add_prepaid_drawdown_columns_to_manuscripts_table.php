<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepayment draw-down credit — see
 * .claude/skills/cncms-context/references/prepayment-drawdown.md.
 *
 * `prepaid_months_remaining` is the count of whole billing periods still
 * covered by a `months`/`yearly` prepayment. Carried forward on every
 * manuscript row (like `payment_expiration` is today), decremented by one
 * each period the customer is billed while it is > 0, at which point
 * `total_bill` is suppressed to 0 (no new monthly charge — the prepayment
 * covers it). Reaches 0 and the customer falls back to the normal ledger
 * branch unchanged.
 *
 * `prepaid_rate` is the customer's `bill` at the moment the prepayment was
 * recorded, snapshotted so it is immune to later rate changes (owner rule:
 * a rate change only takes effect for a prepaid customer after their
 * prepaid months elapse). It is NOT used in the billing arithmetic — a
 * covered month simply isn't charged — it exists only for reporting
 * (deferred revenue = Σ prepaid_months_remaining · prepaid_rate) and refund
 * math (unused value = that product + any loose credit).
 *
 * Nullable / default 0, no backfill: pre-existing rows and customers on the
 * legacy `expiration_date` freeze branch have no draw-down state. The
 * one-off swecom migration (design doc §10 step 4) seeds these for the 22
 * currently-prepaid customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->smallInteger('prepaid_months_remaining')->default(0)->after('payment_expiration');
            $table->decimal('prepaid_rate', 12, 2)->nullable()->after('prepaid_months_remaining');
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropColumn(['prepaid_months_remaining', 'prepaid_rate']);
        });
    }
};
