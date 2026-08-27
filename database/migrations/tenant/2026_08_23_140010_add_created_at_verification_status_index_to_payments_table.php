<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a (created_at, verification_status) composite index matching the
 * "sum(amount) where verification_status = ? and created_at between ? and
 * ?" query shape used by both ResourcesDashboardService::incomeFor() and
 * ManuscriptRepository::collectedForPeriod().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['created_at', 'verification_status'], 'idx_payments_created_at_verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_created_at_verification_status');
        });
    }
};
