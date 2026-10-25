<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A phone's sync pull resends every customer whose payments or bills changed
 * since its last pull (their unpaid debt moved) — App\Services\SyncService::
 * upsertedCustomers(). These indexes keep that lookup cheap on every pull.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['updated_at', 'customer_id'], 'idx_payments_updated_customer');
        });

        Schema::table('manuscripts', function (Blueprint $table): void {
            $table->index(['updated_at', 'customer_id'], 'idx_manuscripts_updated_customer');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('idx_payments_updated_customer');
        });

        Schema::table('manuscripts', function (Blueprint $table): void {
            $table->dropIndex('idx_manuscripts_updated_customer');
        });
    }
};
