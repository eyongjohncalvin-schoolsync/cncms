<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Late registration (owner, 2026-10-26): a customer connected in a month
 * that had closed by the time they were registered.
 *
 *  - customers: why it was late, who really connected them, and what the
 *    customer says they paid and to whom — for auditing ("connected, cash
 *    pocketed, registered late");
 *  - arrears_adjustments.cancels_free_months: "charge every month since
 *    connection" — approving it also ends the customer's installation free
 *    months from the current month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('late_registration_reason', 500)->nullable();
            // Central users table (cross-schema) — no foreign key, like recorded_by.
            $table->unsignedBigInteger('connected_by_user_id')->nullable();
            $table->decimal('customer_says_paid_amount', 12, 2)->nullable();
            $table->string('customer_says_paid_to', 150)->nullable();
        });

        Schema::table('arrears_adjustments', function (Blueprint $table): void {
            $table->boolean('cancels_free_months')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['late_registration_reason', 'connected_by_user_id', 'customer_says_paid_amount', 'customer_says_paid_to']);
        });

        Schema::table('arrears_adjustments', function (Blueprint $table): void {
            $table->dropColumn('cancels_free_months');
        });
    }
};
