<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Expo "ticket" returned by a successful
     * `https://exp.host/--/api/v2/push/send` call (App\Jobs\
     * SendPushNotificationJob) — Expo's send endpoint hands back a ticket id
     * immediately but the real delivery outcome (including
     * DeviceNotRegistered, which must invalidate the token) is only known
     * later via the getReceipts endpoint. App\Jobs\CheckPushReceiptsJob,
     * running every `tasks:run-due` tick (~15 minutes — see
     * App\Support\ScheduledTasks\PushReceiptCheckTaskType), sweeps every
     * still-`pending` row here, checks its receipt, and marks it `checked`
     * either way so the same ticket is never re-checked.
     *
     * Deliberately its own small table rather than a JSON column on
     * device_push_tokens: a single push can fan out to many tokens, and a
     * single token can accumulate many tickets over time — a one-to-many
     * shape, same reasoning as notifications/notification_recipients.
     */
    public function up(): void
    {
        Schema::create('push_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id', 64)->unique();
            $table->foreignId('device_push_token_id')->constrained('device_push_tokens')->cascadeOnDelete();
            // Not a FK to notifications.id — a receipt check must still be
            // able to run even if the originating notification row were
            // ever pruned; source_notification_uuid is kept purely for
            // debugging/traceability.
            $table->uuid('source_notification_uuid')->nullable();
            $table->enum('status', ['pending', 'checked'])->default('pending');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('checked_at')->nullable();

            $table->index('status', 'idx_push_tickets_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tickets');
    }
};
