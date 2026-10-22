<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installation fees, phase 1 (docs/plans/installation-and-promotions.md) —
 * what a payment is FOR:
 *
 * - `subscription` (default) — the monthly bill. The only money billing
 *   (manuscripts, arrears, credit, prepaid draw-down) ever sees. Every
 *   existing row is a subscription payment, and an old mobile build that
 *   never sends a purpose keeps recording subscription payments.
 * - `installation` — pays an installation charge (customer_charge_id),
 *   possibly in instalments. Company income, never subscription credit.
 * - `technician_fee` — the reconnection fine, which goes to the technician
 *   (D13): pass-through cash, never income, bill or arrears.
 *
 * Past reconnection fines keep `subscription` on purpose (D14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('purpose', 20)->default('subscription');
            $table->foreignId('customer_charge_id')->nullable()->constrained('customer_charges')->restrictOnDelete();

            $table->index(['customer_charge_id', 'verification_status'], 'idx_payments_charge_status');
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_purpose_check CHECK (purpose IN ('subscription', 'installation', 'technician_fee'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_installation_charge_check CHECK ((purpose = 'installation') = (customer_charge_id IS NOT NULL))");
        DB::statement("CREATE INDEX idx_payments_purpose_non_subscription ON payments (purpose) WHERE purpose <> 'subscription'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_payments_purpose_non_subscription');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_installation_charge_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_purpose_check');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_charge_status');
            $table->dropConstrainedForeignId('customer_charge_id');
            $table->dropColumn('purpose');
        });
    }
};
