<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintEscalation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The complaint escalation engine (references/complaint-desk.md section 3):
 * the 48h clock, the 4-level threshold table, fixed per-level notification
 * templates, the Level 3 human gate, and the resolution/de-escalation
 * notice. Consumed by:
 *
 *   - App\Support\ScheduledTasks\ComplaintEscalationCheckTaskType, which
 *     calls sweep() once per open complaint on every `tasks:run-due` tick.
 *   - App\Http\Controllers\ComplaintController's "Notify Investors" action,
 *     which calls notifyInvestors() only when a super/admin clicks the
 *     button (never automatically — see notifyInvestors()'s doc comment).
 *   - App\Services\ComplaintService::resolve(), which calls
 *     sendResolutionNotice() after marking a complaint resolved.
 *
 * Escalation levels and their audiences are fixed in code, not
 * admin-configurable (references/complaint-desk.md section 3's explicit
 * "audience and level count are NOT admin-configurable" — matches this
 * app's "small fixed tiers, not open-ended configurability" ethos).
 */
class ComplaintEscalationService
{
    /**
     * Level 1 ("Team escalation") fires once a complaint has sat open this
     * many hours since created_at, per the owner's default in
     * complaint-desk.md section 3's table.
     */
    public const LEVEL_1_HOURS = 24;

    /**
     * Level 2 ("Full staff emergency") fires at this many hours — the
     * owner's exact spec ("48h" is called out explicitly in the table).
     * Level 3 ("Investor notice") is ARMED at the same threshold but never
     * auto-fired — see notifyInvestors().
     */
    public const LEVEL_2_HOURS = 48;

    public const LEVEL_3_ARM_HOURS = 48;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Called once per open, non-duplicate complaint on every scheduler tick
     * (ComplaintRepositoryInterface::openForEscalationSweep() is the exact
     * result set iterated by the caller). Compares elapsed time since
     * created_at against the threshold table and fires whichever of
     * Levels 0/1/2 have newly crossed their threshold and are not yet
     * logged in complaint_escalations for this complaint. Level 3 is
     * deliberately never touched here — see notifyInvestors().
     */
    public function sweep(Complaint $complaint, Carbon $now): void
    {
        $elapsedHours = $complaint->created_at->diffInMinutes($now) / 60;

        // Level 0 — "immediate": not time-gated at all, only gated on
        // whether the complaint currently has an assignee. Deliberately
        // does NOT set escalated_at (see fireLevel2() for why that column
        // is reserved for the genuinely alarming 48h threshold, not this
        // routine "you've been assigned this" routing notice — setting it
        // here would make every freshly-assigned, freshly-filed complaint
        // render with the red "Escalated" badge at age zero, which directly
        // contradicts the visual-language design in complaint-desk.md
        // section 6).
        if ($complaint->assigned_to !== null && ! $this->alreadyFired($complaint, 0)) {
            $this->fireLevel0($complaint);
        }

        if ($elapsedHours >= self::LEVEL_1_HOURS && ! $this->alreadyFired($complaint, 1)) {
            $this->fireLevel1($complaint);
        }

        if ($elapsedHours >= self::LEVEL_2_HOURS && ! $this->alreadyFired($complaint, 2)) {
            $this->fireLevel2($complaint);
        }
    }

    /**
     * The Level 3 human gate (references/complaint-desk.md section 3 —
     * "load-bearing, not optional polish"). Automatic time-based escalation
     * stops at Level 2; this is the ONLY path that ever sends the investor
     * notification, and it only runs when a person actually calls it (from
     * App\Http\Controllers\ComplaintController's notifyInvestors() action,
     * itself gated to super/admin by ComplaintPolicy::notifyInvestors()).
     * sweep() above never calls this.
     *
     * Idempotent (a second click after the first does nothing — same
     * alreadyFired() contract as every automatic level) and re-enforces the
     * 48h arming threshold server-side even though the UI already hides the
     * button before then, since this is a real authorization-adjacent rule,
     * not just a display concern (a crafted request must not be able to
     * jump straight to Level 3 on a brand-new complaint).
     */
    public function notifyInvestors(Complaint $complaint): void
    {
        if ($this->alreadyFired($complaint, 3)) {
            return;
        }

        if (! $this->isInvestorNoticeArmed($complaint)) {
            throw ValidationException::withMessages([
                'complaint' => ['Investors can only be notified once this complaint has been open for 48 hours.'],
            ]);
        }

        DB::transaction(function () use ($complaint): void {
            $this->notifications->broadcastToRole(
                'investor',
                'complaint.investor_notice',
                'emergency',
                $this->title(3),
                $this->body($complaint, 3),
                $this->link($complaint),
            );

            ComplaintEscalation::query()->create([
                'complaint_id' => $complaint->id,
                'level' => 3,
                'notified_scope' => 'role',
                'notified_role' => 'investor',
                'escalated_at' => now(),
            ]);
        });
    }

    /**
     * Whether a 48h-old, unresolved complaint is eligible for the "Notify
     * Investors" button to even be shown/clickable — armed, per
     * complaint-desk.md section 3, but not yet fired.
     */
    public function isInvestorNoticeArmed(Complaint $complaint): bool
    {
        return $complaint->status !== 'resolved'
            && $complaint->created_at->diffInMinutes(now()) / 60 >= self::LEVEL_3_ARM_HOURS;
    }

    /**
     * Resolution/de-escalation notice (references/complaint-desk.md section
     * 3): "query complaint_escalations for that complaint, collect the
     * distinct audiences actually notified across whatever levels were
     * reached, and send one 'resolved' notice to exactly that accumulated
     * audience — never to people who were never escalated to." Called by
     * App\Services\ComplaintService::resolve() after the complaint is
     * already marked resolved.
     *
     * A complaint resolved before any escalation ever fired has zero
     * complaint_escalations rows — this sends nothing at all in that case,
     * matching "never to people who were never escalated to" literally
     * (there is no one to notify).
     *
     * If Level 2 (or the Level 3 investor notice) was ever reached, the
     * accumulated audience already includes 'all' tenant users via
     * broadcastToAll's role-independent scope, so that alone is sent rather
     * than also separately re-notifying every narrower role/user gathered
     * from earlier levels (redundant — everyone is already covered).
     */
    public function sendResolutionNotice(Complaint $complaint): void
    {
        $rows = $complaint->escalations()->get();

        if ($rows->isEmpty()) {
            return;
        }

        $title = $this->title(-1, resolved: true);
        $body = $this->body($complaint, -1, resolved: true);
        $link = $this->link($complaint);

        if ($rows->contains(fn (ComplaintEscalation $row): bool => $row->notified_scope === 'all')) {
            $this->notifications->broadcastToAll('complaint.resolved', 'info', $title, $body, $link);

            return;
        }

        $roles = $rows->where('notified_scope', 'role')->pluck('notified_role')->filter()->unique();

        foreach ($roles as $role) {
            $this->notifications->broadcastToRole($role, 'complaint.resolved', 'info', $title, $body, $link);
        }

        $userIds = $rows->where('notified_scope', 'user')->pluck('notified_user_id')->filter()->unique();

        if ($userIds->isNotEmpty()) {
            User::query()->whereIn('id', $userIds)->get()->each(
                fn (User $user) => $this->notifications->broadcastToUser($user, 'complaint.resolved', 'info', $title, $body, $link)
            );
        }
    }

    private function alreadyFired(Complaint $complaint, int $level): bool
    {
        return ComplaintEscalation::query()
            ->where('complaint_id', $complaint->id)
            ->where('level', $level)
            ->exists();
    }

    /**
     * "assignee + their manager, if set" (complaint-desk.md section 3's
     * table) — this app has no per-user "who is my manager" relationship
     * (roles are flat per tenant_users.role, not hierarchical), so "their
     * manager" is read as this codebase's usual shorthand for the `manager`
     * role collectively, matching how ComplaintPolicy::linkDuplicate()'s own
     * doc comment already reads "manager-only" as inclusive-of-tier
     * language elsewhere in this same feature.
     */
    private function fireLevel0(Complaint $complaint): void
    {
        DB::transaction(function () use ($complaint): void {
            $assignee = $complaint->assignedTo ?? User::query()->find($complaint->assigned_to);

            if ($assignee !== null) {
                $this->notifications->broadcastToUser(
                    $assignee,
                    'complaint.assigned',
                    'info',
                    $this->title(0),
                    $this->body($complaint, 0),
                    $this->link($complaint),
                );

                ComplaintEscalation::query()->create([
                    'complaint_id' => $complaint->id,
                    'level' => 0,
                    'notified_scope' => 'user',
                    'notified_user_id' => $assignee->id,
                    'escalated_at' => now(),
                ]);
            }

            $this->notifications->broadcastToRole(
                'manager',
                'complaint.assigned',
                'info',
                $this->title(0),
                $this->body($complaint, 0),
                $this->link($complaint),
            );

            ComplaintEscalation::query()->create([
                'complaint_id' => $complaint->id,
                'level' => 0,
                'notified_scope' => 'role',
                'notified_role' => 'manager',
                'escalated_at' => now(),
            ]);
        });
    }

    private function fireLevel1(Complaint $complaint): void
    {
        DB::transaction(function () use ($complaint): void {
            foreach (['super', 'admin', 'manager'] as $role) {
                $this->notifications->broadcastToRole(
                    $role,
                    'complaint.escalated_level_1',
                    'urgent',
                    $this->title(1),
                    $this->body($complaint, 1),
                    $this->link($complaint),
                );

                ComplaintEscalation::query()->create([
                    'complaint_id' => $complaint->id,
                    'level' => 1,
                    'notified_scope' => 'role',
                    'notified_role' => $role,
                    'escalated_at' => now(),
                ]);
            }
        });
    }

    /**
     * The one column write this whole engine makes to `complaints` itself:
     * escalated_at, set once, only here — see Complaint's class doc
     * ("set once by the escalation checker the first time the 48h
     * threshold fires"). Deliberately guarded by `whereNull('escalated_at')`
     * at the query level (not just a PHP null-check) so a concurrent second
     * tick can never stomp an already-set value back to a later timestamp.
     */
    private function fireLevel2(Complaint $complaint): void
    {
        DB::transaction(function () use ($complaint): void {
            $this->notifications->broadcastToAll(
                'complaint.escalated_level_2',
                'emergency',
                $this->title(2),
                $this->body($complaint, 2),
                $this->link($complaint),
            );

            ComplaintEscalation::query()->create([
                'complaint_id' => $complaint->id,
                'level' => 2,
                'notified_scope' => 'all',
                'escalated_at' => now(),
            ]);

            Complaint::query()->whereKey($complaint->id)->whereNull('escalated_at')->update(['escalated_at' => now()]);
            $complaint->refresh();
        });
    }

    private function link(Complaint $complaint): string
    {
        return "/complaints/{$complaint->uuid}";
    }

    /**
     * Fixed templates, one per level (references/complaint-desk.md section
     * 3: "not admin-editable free text ... proportionate to 4 fixed levels,
     * avoids validation/injection surface and per-locale duplication").
     * $level = -1 is the resolution/de-escalation notice, not one of the 4
     * escalation levels itself, but shares this same "fixed template" shape.
     */
    private function title(int $level, bool $resolved = false): string
    {
        if ($resolved) {
            return 'Complaint Resolved';
        }

        return match ($level) {
            0 => 'Complaint Assigned',
            1 => 'Complaint Escalated — Team Notice (24h)',
            2 => 'Complaint Escalated — Emergency (48h)',
            3 => 'Complaint Escalated — Investor Notice',
            default => 'Complaint Update',
        };
    }

    private function body(Complaint $complaint, int $level, bool $resolved = false): string
    {
        $title = $complaint->title;
        $link = $this->link($complaint);

        if ($resolved) {
            return "Complaint \"{$title}\" has been resolved. View it: {$link}";
        }

        return match ($level) {
            0 => "Complaint \"{$title}\" has been assigned and requires attention. View it: {$link}",
            1 => "Complaint \"{$title}\" has been open for over 24 hours without resolution. Team escalation triggered. View it: {$link}",
            2 => "EMERGENCY: Complaint \"{$title}\" has been open for over 48 hours without resolution. Full staff escalation triggered. View it: {$link}",
            3 => "Complaint \"{$title}\" has remained unresolved past the 48-hour escalation window and has been flagged for investor visibility. View it: {$link}",
            default => "Complaint \"{$title}\" has been updated. View it: {$link}",
        };
    }
}
