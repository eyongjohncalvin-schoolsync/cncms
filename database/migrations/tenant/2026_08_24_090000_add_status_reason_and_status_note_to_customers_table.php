<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the dedicated disconnect/suspend/reconnect status actions (see
 * App\Services\CustomerStatusService) — distinct from a free-text edit on
 * the generic customer form. `status_reason` is a short machine-readable
 * code (e.g. 'non_payment', 'tv_problem', 'poor_service', 'customer_request',
 * 'zone_transfer', 'other', 'reconnected'); `status_note` is the optional
 * free-text detail an office user types (required when `status_reason` is
 * 'other'). Both are nullable — the generic PATCH /customers/{customer}
 * update route can still change `status` alone without touching these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('status_reason', 30)->nullable()->after('status');
            $table->text('status_note')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'status_note']);
        });
    }
};
