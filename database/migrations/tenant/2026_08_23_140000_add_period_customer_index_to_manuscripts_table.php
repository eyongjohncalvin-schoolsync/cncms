<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a (period, customer_id) composite index matching the query shape
 * used to list/aggregate manuscripts for a given billing period (period
 * first, then optionally filtered/joined by customer). The existing
 * idx_manuscripts_customer_period index (customer_id, period) is left in
 * place — it serves a different query shape (historyForCustomer() /
 * ManuscriptCalculator's per-customer lookups, customer_id first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->index(['period', 'customer_id'], 'idx_manuscripts_period_customer');
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropIndex('idx_manuscripts_period_customer');
        });
    }
};
