<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The one real cron tick for the generic task scheduler (task-scheduler.md
// section 3) — every 15 minutes matches the granularity both manuscript
// day-of-month scheduling and (future) escalation-checking actually need;
// nothing here needs sub-15-minute precision. See
// App\Console\Commands\TasksRunDue for what actually runs on each tick.
//
// This alone does not make the schedule fire in production: Laravel's
// scheduler still needs something invoking `php artisan schedule:run` every
// minute (Laravel Cloud does this automatically for a deployed environment —
// see .claude/skills/deploying-laravel-cloud — but that has not been
// confirmed against this app's actual deployed environment from this
// sandbox, which has no `cloud` CLI/auth available; see this feature's
// build notes). Locally, `php artisan schedule:work` runs the same loop for
// development.
// withoutOverlapping(): a tick that's still running when the next one fires
// (a slow tenant loop, a stuck queue, etc.) must not start a second
// concurrent `tasks:run-due` process on top of it — Laravel's own
// schedule-mutex file lock, not a DB constraint, is the right tool for this
// specific race (two invocations of the SAME artisan command), distinct
// from (and a defense-in-depth complement to) idx_command_runs_period_inflight
// (see that migration's doc comment), which guards the narrower case of two
// independently-dispatched manuscript_generation runs for the same period
// regardless of what triggered them.
Schedule::command('tasks:run-due')->everyFifteenMinutes()->withoutOverlapping();
