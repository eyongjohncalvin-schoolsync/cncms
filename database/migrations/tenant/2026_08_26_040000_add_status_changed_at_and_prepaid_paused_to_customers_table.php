<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the prepaid-time preservation feature — see
 * .claude/skills/cncms-context/references/prepaid-pause-handling.md.
 *
 * `status_changed_at` (nullable, no backfill) is the "freeze began at"
 * anchor App\Services\CustomerStatusService::reconnectOne() needs to
 * compute how long a disconnect/suspend freeze actually lasted. It is set
 * on every status transition CustomerStatusService performs (disconnect,
 * suspend, reconnect) going forward; existing customers simply have NULL
 * here until their next transition, which is deliberate — see the design
 * doc's section 6: this feature does not retroactively reconstruct a
 * duration for freezes that began before it shipped, it only skips the
 * extension for those (CustomerStatusService::extendPrepaidWindow()
 * no-ops on a null anchor).
 *
 * `prepaid_paused` (nullable) is a one-suspension-cycle flag, not a
 * standing customer property: set at suspend time only when the customer
 * had an active/unexpired prepaid window (so the admin's "pause vs.
 * continue" choice was actually relevant), read once at the matching
 * reconnect, then always cleared back to NULL regardless of outcome. It is
 * meaningless outside an active suspension and is never consulted for the
 * `disconnected` path, whose extension is unconditional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestampTz('status_changed_at')->nullable()->after('status_note');
            $table->boolean('prepaid_paused')->nullable()->after('status_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['status_changed_at', 'prepaid_paused']);
        });
    }
};
