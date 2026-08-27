<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retires the dormant `alerts` table (in-app-notifications.md section
     * 7) — created 2026-08-19 (see
     * 2026_08_19_090604_create_alerts_table.php) but never wired to
     * anything: no recipient column, no read state, and App\Models\Alert
     * was referenced nowhere in app/ except one "future extension point"
     * doc comment on App\Services\CustomerEligibilityService (updated
     * alongside this migration to drop the dead reference). Superseded by
     * the real `notifications`/`notification_recipients` pair added just
     * before this migration — leaving `alerts` in place would have meant
     * two half-formed notification concepts living side by side.
     */
    public function up(): void
    {
        Schema::dropIfExists('alerts');
    }

    /**
     * Recreates the table exactly as it was originally created, for a
     * clean rollback.
     */
    public function down(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 50);
            $table->text('message');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }
};
