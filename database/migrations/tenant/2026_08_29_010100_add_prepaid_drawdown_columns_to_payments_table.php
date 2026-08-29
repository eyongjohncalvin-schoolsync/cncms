<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepayment draw-down credit — see
 * .claude/skills/cncms-context/references/prepayment-drawdown.md.
 *
 * `prepaid_rate` — set by PaymentService::create() on a `months`/`yearly`
 * payment to the customer's `bill` at that moment. Carried onto the
 * customer's manuscript row (`manuscripts.prepaid_rate`) by the calculator
 * when the prepayment is absorbed. Null for `monthly` payments and for every
 * payment recorded before this feature.
 *
 * `clear_arrears_first` — the agent's toggle at payment time (owner ruling
 * Q1). When true, a `months`/`yearly` payment first pays down the customer's
 * outstanding arrears (`min(amount, arrears)`) and only the remainder buys
 * prepaid months; when false (default) the arrears carry forward untouched
 * and the full amount buys prepaid months. Stored for the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('prepaid_rate', 12, 2)->nullable()->after('months');
            $table->boolean('clear_arrears_first')->default(false)->after('prepaid_rate');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['prepaid_rate', 'clear_arrears_first']);
        });
    }
};
