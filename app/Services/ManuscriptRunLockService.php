<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Single source of truth for "is this manuscript period locked" — the
 * manuscript-run-management feature's one flat rule
 * (`.claude/skills/cncms-context/references/task-scheduler.md`'s 2026-08-28
 * addendum). Every cancel/delete/rollback action on a `command_runs` row
 * (`command = 'manuscript:calculate'`) must call {@see isPeriodLocked()}
 * before doing anything — never re-derive the comparison inline, per this
 * codebase's standing access-control-simplicity principle (no cascading
 * overrides, no per-role exceptions).
 *
 * "Current period" is defined identically to every other place in this app
 * that means "now, as a billing period" — `Carbon::now()->format('Y-m')`,
 * a bare calendar-month string (see App\Http\Controllers\ManuscriptController,
 * App\Console\Commands\ManuscriptCalculate, App\Services\ManuscriptService —
 * all use this exact expression; business-rules.md never describes period
 * advancement as "last published period + 1", and nothing in this codebase
 * computes it that way). It is NOT derived from the latest published/run
 * `command_runs` row — a tenant that has never run a calculation for the
 * current calendar month still has that month as its current, mutable
 * period; a tenant sitting on a stale, un-run current month does not make
 * last month "current" again.
 *
 * A period is locked when it is strictly BEFORE the current calendar month
 * — i.e. it has already passed.
 *
 * 2026-08-28 correction: this used to be a simple `!==` (anything other
 * than current is locked), reasoned as safe because every entry point
 * rejected a future period outright, so a future-period row could never
 * exist to need distinguishing from "past". That assumption no longer
 * holds — business-rules.md's 2026-08-28 correction means a run's period is
 * now the month it GOVERNS, and since it's triggered near month-end, its
 * default is legitimately `now()->addMonthNoOverflow()->format('Y-m')` (one
 * calendar month ahead) — an advance run for the upcoming month is now the
 * NORMAL case, not an impossible one. A `!==` check would wrongly lock a
 * freshly-created, entirely unpublished next-month run the instant it's
 * created, purely because it doesn't equal `currentPeriod()`. Strict `<`
 * fixes this: current AND next-month periods both stay mutable; only a
 * period that has actually elapsed locks.
 */
class ManuscriptRunLockService
{
    public function currentPeriod(): string
    {
        return Carbon::now()->format('Y-m');
    }

    /**
     * True when $period is strictly before the current billing period — i.e.
     * it has already passed. Locked means: fully read-only, no cancel/
     * delete/rollback action available, regardless of the individual
     * command_run's own status (queued/pending_review/published/failed all
     * lock identically once their period is in the past) — a stale/
     * abandoned row against a past period must not become actionable just
     * because it never got published. A period equal to or one month AHEAD
     * of current (the normal advance-billing case) is never locked.
     */
    public function isPeriodLocked(string $period): bool
    {
        return $period < $this->currentPeriod();
    }
}
