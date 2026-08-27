<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixes the manuscript-recalculation idempotency bug (see
     * App\Services\ManuscriptCalculator's class doc): `processed_at` being a
     * plain nullable timestamp meant "has this payment EVER been consumed by
     * ANY period's calculation" — a boolean flag with no memory of WHICH
     * period consumed it. Re-running the SAME period after its first run
     * therefore found zero eligible payments (all already stamped) and
     * fabricated a full new bill's worth of arrears on top of an
     * already-correct number, since the previous-period baseline it reads is
     * unaffected by the rerun.
     *
     * `processed_period` ('YYYY-MM', nullable) makes payment consumption
     * period-attributed instead of a one-way flag: a payment is eligible for
     * period P's calculation when `processed_period IS NULL` (never
     * consumed by any period yet — including a frozen customer's payments
     * that carry forward indefinitely across disconnected/passive/prepaid
     * periods, exactly as before) OR `processed_period = P` (already
     * consumed by THIS SAME period, so a rerun of P sees the identical set
     * again). Once stamped to a specific period, a payment can never again
     * satisfy a DIFFERENT period's eligibility check — preserving the
     * "counted in exactly one period, ever" guarantee `processed_at IS NULL`
     * used to provide, but now safely re-runnable.
     *
     * `processed_at` itself is untouched and keeps being stamped alongside
     * `processed_period` — it remains useful purely as a "when was this
     * processed" display/audit timestamp (PaymentResource, Payments/Show.tsx,
     * PaymentController) and is no longer read by the billing engine's
     * eligibility query at all.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('processed_period', 7)->nullable()->after('processed_at');

            $table->index(['customer_id', 'processed_period'], 'idx_payments_customer_processed_period');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_customer_processed_period');
            $table->dropColumn('processed_period');
        });
    }
};
