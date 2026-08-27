<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix 2 (2026-08 audit): `manuscripts.customer_id` and `messages.customer_id`
 * were both created with cascadeOnDelete() (2026_08_19_090533_create_
 * manuscripts_table.php, 2026_08_19_090550_create_messages_table.php) — an
 * oversight, not a deliberate choice, since `payments.customer_id` already
 * correctly uses restrictOnDelete() (2026_08_19_090516_create_payments_
 * table.php) to protect payment history from being silently destroyed by a
 * customer delete. There is no reason billing/arrears history (manuscripts)
 * or SMS history (messages) would deserve less protection than payment
 * history. Worse, because the delete happens at the DATABASE level
 * (ON DELETE CASCADE), Eloquent's Auditable trait never sees the cascaded
 * manuscript/message deletions at all — only the Customer row's own deletion
 * gets an audit_logs entry, so a customer delete could silently wipe years of
 * arrears history with zero trace.
 *
 * This app already has production tenants migrated (swecom,
 * multimedia-digital-cable-network), so the original migration is left
 * as-is and this new migration ALTERs the existing constraint instead.
 * Postgres has no "change the ON DELETE action" ALTER — the constraint must
 * be dropped and re-added. Laravel's dropForeign()/foreign() pair (rather
 * than raw DB::statement) is used so the down() migration is the exact
 * mirror, and so this stays consistent with how the rest of this codebase's
 * migrations declare foreign keys.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }
};
