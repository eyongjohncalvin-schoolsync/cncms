<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per logical event (in-app-notifications.md section 2) — never
     * duplicated per recipient at write time. `notification_recipients`
     * (next migration) is where lazy, per-user read/acknowledge state lives;
     * see that migration's doc comment and section 3 of the spec for why
     * this is a deliberate two-table split rather than one wide table.
     *
     * No `updated_at`: a notification event never changes after it's
     * created — same "write-once" shape as `audit_logs`, though unlike that
     * table this one has no DELETE-blocking RULE (nothing here needs to be
     * literally immutable/tamper-evident, it's just never expected to be
     * edited).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            // Dot-namespaced, e.g. "complaint.escalated" — mirrors this
            // app's existing namespace:action artisan-command convention
            // (in-app-notifications.md section 2).
            $table->string('type', 100);
            $table->enum('severity', ['info', 'warning', 'urgent', 'emergency'])->default('info');
            $table->string('title');
            $table->text('body');
            // Deep-link path, e.g. "/complaints/{uuid}" — nullable, some
            // notifications may have nothing to link to.
            $table->string('link')->nullable();
            // Points back to the originating entity independent of `link`,
            // so "all notifications about complaint X" stays queryable even
            // if URL shapes change later. Both nullable together.
            $table->string('source_type', 100)->nullable();
            $table->uuid('source_uuid')->nullable();
            // Explicit discriminator, not inferred from nullability of the
            // recipient_* columns below.
            $table->enum('broadcast_scope', ['user', 'role', 'all']);
            // Cross-schema FK to public.users — set only when
            // broadcast_scope = 'user'. Not a plain string role: investors
            // are addressed via recipient_role = 'investor', matched at
            // query time against tenant_users.is_investor (there is no
            // 'investor' value in the tenant_users.role enum — see
            // rbac-permissions.md section 7) rather than a literal role
            // column match.
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('recipient_role', 50)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('uuid', 'idx_notifications_uuid');
            $table->index(['broadcast_scope', 'recipient_role'], 'idx_notifications_scope_role');
            $table->index(['broadcast_scope', 'recipient_user_id'], 'idx_notifications_scope_user');
            $table->index(['source_type', 'source_uuid'], 'idx_notifications_source');
            $table->index('severity', 'idx_notifications_severity');
            $table->index('created_at', 'idx_notifications_created');
        });

        // Cross-schema FK: tenant search_path does not implicitly include
        // `public`, so the target is schema-qualified explicitly in a raw
        // statement — same pattern as audit_logs/tenant_users.
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_recipient_user_id_foreign FOREIGN KEY (recipient_user_id) REFERENCES public.users(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
