<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-configurable two-approver threshold for the Arrears Adjustment
 * feature (App\Services\ArrearsAdjustmentService) — same
 * "admin-configurable amount on `companies`" pattern as reconnection_fine
 * (see 2026_08_24_100000_add_reconnection_fine_to_companies_table.php),
 * read fresh from Company::cached() rather than hardcoded, defaulting to
 * 20,000 FCFA per the feature's synthesized design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('arrears_second_approval_threshold', 12, 2)->default(20000)->after('reconnection_fine');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('arrears_second_approval_threshold');
        });
    }
};
