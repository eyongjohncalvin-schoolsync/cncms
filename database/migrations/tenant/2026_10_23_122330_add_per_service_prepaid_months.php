<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installation phase 2 revision (D15): months paid upfront are chosen PER
 * SERVICE at registration and follow straight after that service's free
 * months.
 *
 *  - payments.service_months — what a "paid upfront" payment bought:
 *    [{service_id, months, rate}], each service at its own price (locked).
 *  - manuscripts.service_prepaid — the per-service counters carried forward
 *    month to month: {"<service_id>": {"months": n, "rate": "2500.00"}}. A
 *    month a service is free (or the customer is frozen) uses none, so N
 *    paid months always cover N billed months of that service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->jsonb('service_months')->nullable();
        });

        Schema::table('manuscripts', function (Blueprint $table) {
            $table->jsonb('service_prepaid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', fn (Blueprint $table) => $table->dropColumn('service_prepaid'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('service_months'));
    }
};
