<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sync-push idempotency for complaints — mirrors
 * 2026_08_25_100000_add_local_uuid_to_payments_table.php and
 * 2026_08_25_100010_add_local_uuid_to_expenditures_table.php exactly, for
 * the identical reason (mobile-app-react-native.md section 3 / the
 * complaint-desk.md section 7 mobile submission screen): a client-generated
 * `local_uuid` lets a retried push (dropped connection right after the
 * server already committed the row — routine on flaky field connectivity)
 * be recognized as "already applied" and short-circuited to the existing
 * `server_uuid` in App\Services\SyncService::pushComplaint(), instead of
 * creating a second complaint row.
 *
 * Nullable — every complaint submitted through the web form has no
 * local_uuid; Postgres allows multiple NULLs in a UNIQUE column by default,
 * so that's a deliberate no-op, not a gap. Unique — so two pushes carrying
 * the same local_uuid can never both succeed at create() even under a
 * race, the actual correctness guarantee this column exists for.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->uuid('local_uuid')->nullable()->unique()->after('uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('local_uuid');
        });
    }
};
