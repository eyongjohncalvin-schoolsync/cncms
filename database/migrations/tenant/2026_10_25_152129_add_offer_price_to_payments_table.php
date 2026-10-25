<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment under a prepayment offer may also clear the customer's old debt
 * in the same payment ("collect 12,000: 2,000 clears the debt, 10,000 buys
 * the offer"). The offer's price at the time is kept on the payment, so the
 * bill run knows how much of the money buys the offer once the debt is paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->decimal('offer_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('offer_price');
        });
    }
};
