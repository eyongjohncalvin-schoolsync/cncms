<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sync-push idempotency for expenditures — mirrors
 * 2026_08_25_100000_add_local_uuid_to_payments_table.php exactly, for
 * App\Services\SyncService::pushExpenditure(). See that migration's doc
 * comment for the full rationale (nullable + unique, NULLs deliberately
 * unconstrained under Postgres's default UNIQUE-column behavior).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('expenditures', function (Blueprint $table) {
            $table->uuid('local_uuid')->nullable()->unique()->after('uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenditures', function (Blueprint $table) {
            $table->dropColumn('local_uuid');
        });
    }
};
