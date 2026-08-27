<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors 2026_08_27_000000_add_collected_at_to_payments_table.php exactly,
 * for the identical reason: preserves the field agent's actual
 * offline-submission timestamp for a synced complaint without touching
 * `created_at`'s server-arrival semantics. See
 * App\Services\SyncService::pushComplaint()'s doc comment for the client
 * `created_at` -> column `collected_at` mapping.
 *
 * Nullable — every web-submitted complaint has no client-side collection
 * timestamp at all, so this stays null for every non-sync create() call
 * site; only SyncService::pushComplaint() ever populates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->timestamp('collected_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('collected_at');
        });
    }
};
