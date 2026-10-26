<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner, 2026-10-26: months that are covered — free, paid ahead, or an
 * old-system "covered until" date — are paid. The first month after them is
 * billed normally (that month's bill, nothing owed); see
 * App\Services\ManuscriptCalculator. A bill now remembers whether its month
 * was covered, so the next run knows the customer has just come out of
 * covered months.
 *
 * Existing bills are filled in from what they already show:
 *  - an old-system date covering the bill's month — on the bill itself, or
 *    on the month before it (the latest bills whose date was converted into
 *    prepaid months on 2026-10-26);
 *  - a month paid ahead (prepaid months counting down, nothing charged).
 * Free months need nothing stored: they run by calendar on the grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table): void {
            // The whole bill was free or paid ahead this month.
            $table->boolean('covered')->default(false);
            // Services paid ahead this month on their own (per-service months, D15).
            $table->jsonb('covered_services')->nullable();
        });

        DB::statement(<<<'SQL'
            WITH ordered AS (
                SELECT id, period, bill, total_bill, total_arrears, payment_expiration, prepaid_months_remaining,
                       LAG(payment_expiration) OVER (PARTITION BY customer_id ORDER BY period) AS previous_expiration,
                       LAG(prepaid_months_remaining) OVER (PARTITION BY customer_id ORDER BY period) AS previous_prepaid
                FROM manuscripts
            )
            UPDATE manuscripts m SET covered = true
            FROM ordered o
            WHERE m.id = o.id
              AND (
                    to_char(o.payment_expiration, 'YYYY-MM') >= o.period
                 OR to_char(o.previous_expiration, 'YYYY-MM') >= o.period
                 OR ((o.prepaid_months_remaining > 0 OR COALESCE(o.previous_prepaid, 0) > 0)
                     AND o.bill > 0 AND o.total_bill = o.total_arrears)
              )
            SQL);
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table): void {
            $table->dropColumn(array_values(array_filter(['covered', 'covered_services'], fn (string $column): bool => Schema::hasColumn('manuscripts', $column))));
        });
    }
};
