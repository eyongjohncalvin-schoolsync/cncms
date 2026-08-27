<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expo push registration for the mobile app (mobile-push-notifications
     * build notes) — one row per (user, device). `device_id` reuses the
     * SAME uuid the mobile app already generates once for sync
     * (mobile/src/db/syncMeta.ts's getOrCreateDeviceId(), sent as
     * sync_queue's `device_id` and stored server-side on agents.sync_token)
     * rather than inventing a second device identity — a token registration
     * and a sync push from the same install always carry the same
     * `device_id` value.
     *
     * Cross-schema FK to `public.users` — same raw DB::statement pattern as
     * notifications.recipient_user_id (tenant search_path does not include
     * `public`).
     *
     * `is_valid` is flipped false the instant Expo's send response (or a
     * later receipt check) reports DeviceNotRegistered for a ticket sent to
     * this token — see App\Jobs\SendPushNotificationJob/CheckPushReceiptsJob.
     * A row is never deleted on invalidation (kept for audit/debugging); a
     * fresh registration for the same (user_id, device_id) simply flips it
     * back to true and updates the token via the unique-key upsert in
     * App\Repositories\Eloquent\PushTokenRepository::register().
     */
    public function up(): void
    {
        Schema::create('device_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('user_id');
            $table->string('device_id', 100);
            $table->string('expo_push_token', 255);
            $table->enum('platform', ['ios', 'android']);
            $table->boolean('is_valid')->default(true);
            $table->timestampTz('registered_at')->useCurrent();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['user_id', 'device_id'], 'uq_device_push_tokens_user_device');
            $table->index('is_valid', 'idx_device_push_tokens_is_valid');
            $table->index('expo_push_token', 'idx_device_push_tokens_token');
        });

        DB::statement('ALTER TABLE device_push_tokens ADD CONSTRAINT device_push_tokens_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('device_push_tokens');
    }
};
