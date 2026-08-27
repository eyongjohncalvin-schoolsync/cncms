<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The escalation engine's log table (references/complaint-desk.md
     * section 3): one row per notification actually sent for a given
     * complaint/level, NOT one row per level. A level can address more than
     * one distinct target in a single firing (Level 0 = the assignee, a
     * specific `notified_user_id`, PLUS the 'manager' role; Level 1 = three
     * separate role broadcasts — 'super', 'admin', 'manager') and the
     * resolution/de-escalation notice (App\Services\ComplaintEscalationService
     * ::sendResolutionNotice()) needs to know exactly who was addressed, not
     * just which levels fired, so a row per actual NotificationService call
     * is the honest granularity — mirrors how `notifications` itself is one
     * row per broadcast_scope, not one row per recipient.
     *
     * Idempotency (a second scheduler tick within the same window must not
     * re-notify) is checked per (complaint_id, level): if ANY row exists for
     * that pair, that level has already fired and is skipped entirely — see
     * App\Services\ComplaintEscalationService::alreadyFired().
     *
     * Level 3 (the Investor notice) is human-gated (complaint-desk.md
     * section 3's load-bearing safeguard) — a row here at level=3 is written
     * only when App\Services\ComplaintEscalationService::notifyInvestors()
     * runs (triggered by a super/admin clicking "Notify Investors"), never
     * by the automatic scheduler sweep.
     */
    public function up(): void
    {
        Schema::create('complaint_escalations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('complaint_id')->constrained('complaints')->cascadeOnDelete();
            // 0 = Assigned, 1 = Team escalation (24h), 2 = Full staff
            // emergency (48h), 3 = Investor notice (human-gated) — see
            // references/complaint-desk.md section 3's table. Not a DB enum:
            // same "closed set enforced in code, not a DB constraint"
            // reasoning as scheduled_tasks.task_type, since level count is
            // fixed in App\Services\ComplaintEscalationService, not admin
            // data.
            $table->unsignedTinyInteger('level');
            $table->enum('notified_scope', ['user', 'role', 'all']);
            // Set only when notified_scope = 'role' — e.g. 'manager',
            // 'super', 'admin', 'investor'. 'investor' here is a plain
            // descriptor string (matches what was passed to
            // NotificationService::broadcastToRole('investor', ...)), not a
            // literal tenant_users.role enum value — see Notification::
            // matchesAudience()'s doc comment for why those are different
            // things in this codebase.
            $table->string('notified_role', 50)->nullable();
            // Set only when notified_scope = 'user' (Level 0's assignee).
            // Cross-schema FK into public.users, same raw DB::statement
            // pattern as complaints.assigned_to — captures WHO was actually
            // notified at the time, independent of whatever
            // complaints.assigned_to says later (the assignee can change
            // after this fires; the resolution notice must still reach the
            // person who was genuinely notified back then).
            $table->unsignedBigInteger('notified_user_id')->nullable();
            $table->timestampTz('escalated_at');
            // Write-once, no updated_at — same shape as `notifications`.
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['complaint_id', 'level'], 'idx_complaint_escalations_complaint_level');
        });

        DB::statement('ALTER TABLE complaint_escalations ADD CONSTRAINT complaint_escalations_notified_user_id_foreign FOREIGN KEY (notified_user_id) REFERENCES public.users(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_escalations');
    }
};
