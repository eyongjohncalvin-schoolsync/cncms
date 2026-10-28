<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Late registration corrections (owner, 2026-10-28): an approved old-debt
 * charge is undone by an equal credit through the same approval — the
 * reversal points at the charge it undoes — and a charge that ended the
 * customer's free months remembers how many each grant had, so reversing
 * it gives them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arrears_adjustments', function (Blueprint $table) {
            $table->foreignId('reverses_adjustment_id')->nullable()->constrained('arrears_adjustments')->restrictOnDelete();
        });

        Schema::table('free_month_grants', function (Blueprint $table) {
            $table->smallInteger('months_before_cancel')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('arrears_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_adjustment_id');
        });

        Schema::table('free_month_grants', function (Blueprint $table) {
            $table->dropColumn('months_before_cancel');
        });
    }
};
