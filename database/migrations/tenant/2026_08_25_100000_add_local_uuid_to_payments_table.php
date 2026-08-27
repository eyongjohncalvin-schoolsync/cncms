<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sync-push idempotency for payments (see
 * App\Services\SyncService::pushPayment() and
 * .ai/skills/cncms/cncms-context/references/mobile-app-react-native.md
 * section 3): a client-generated `local_uuid` lets a retried push (e.g. a
 * dropped connection right after the server already committed the row) be
 * recognized as "already applied" and short-circuited to the existing
 * `server_uuid` instead of creating a duplicate payment.
 *
 * Nullable — existing rows (and any push that predates this column) simply
 * have no local_uuid; Postgres allows multiple NULLs in a UNIQUE column by
 * default, so that's a deliberate no-op for old data, not a gap. Unique —
 * so two pushes carrying the same local_uuid can never both succeed at
 * create() even under a race, the actual correctness guarantee this column
 * exists for.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('local_uuid')->nullable()->unique()->after('uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('local_uuid');
        });
    }
};
