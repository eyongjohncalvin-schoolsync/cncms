<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-recipient state, lazily materialized — a row here is only ever
     * written the first time a given user reads or acknowledges a given
     * notification, never eagerly for every matching user at broadcast
     * time (in-app-notifications.md section 3). "Unread for me" is
     * therefore `notifications WHERE (audience matches me) AND NOT EXISTS
     * (a row here for me)` — see App\Repositories\Eloquent\
     * NotificationRepository::unreadCountForUser().
     *
     * `read_at` and `acknowledged_at` are genuinely separate columns from
     * day one (section 5): read is passive (opened in the dropdown),
     * acknowledged is an explicit action (a dedicated "Acknowledge"
     * button — see references/complaint-desk.md section 6). Neither
     * column implies the other here at the schema/repository level.
     */
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            // Cross-schema FK to public.users — see the same note on
            // notifications.recipient_user_id.
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['notification_id', 'user_id'], 'uniq_notification_recipient');
            $table->index('user_id', 'idx_notification_recipients_user');
        });

        DB::statement('ALTER TABLE notification_recipients ADD CONSTRAINT notification_recipients_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
