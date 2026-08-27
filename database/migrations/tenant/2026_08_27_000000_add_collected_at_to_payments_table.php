<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserves the field agent's actual offline-collection timestamp for a
 * synced payment, WITHOUT touching `created_at`'s existing "when this row
 * landed on the server" semantics. `created_at` deliberately keeps meaning
 * server-arrival time — App\Http\Controllers\PaymentController::index()'s
 * month-scoping/"Today" filter and the daily-close-of-day design both
 * already rely on that meaning, and repurposing `created_at` to hold the
 * client's timestamp would silently break both.
 *
 * The mobile app already sends this timestamp on every queued payment (as
 * `created_at` in the wire payload — see
 * mobile/src/sync/SyncManager.ts:281 — validated by SyncPushRequest as
 * `changes.payments.*.created_at`), but until now it was silently discarded
 * by App\Services\SyncService::pushPayment(). See that method's doc comment
 * for the client-field-name -> column-name mapping.
 *
 * Nullable — every web-recorded payment (StorePaymentRequest) has no
 * client-side collection timestamp at all, so this stays null for every
 * non-sync create() call site; only SyncService::pushPayment() ever
 * populates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('collected_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('collected_at');
        });
    }
};
