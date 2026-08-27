<?php

declare(strict_types=1);

use App\Support\ScheduledTasks\ComplaintEscalationCheckTaskType;
use App\Support\ScheduledTasks\ManuscriptGenerationTaskType;
use App\Support\ScheduledTasks\PushReceiptCheckTaskType;

return [

    /*
    |--------------------------------------------------------------------------
    | Task type registry
    |--------------------------------------------------------------------------
    |
    | App\Console\Commands\TasksRunDue resolves each enabled scheduled_tasks
    | row's handler from this map (task_type => App\Contracts\ScheduledTaskType
    | implementation, resolved via the container so its own dependencies are
    | injected normally). This is the ONE place a new task type is registered
    | — task-scheduler.md section 1's whole point is that adding
    | bill_generation/report_generation/bulk_notification, or the Complaint
    | Desk feature's complaint_escalation_check, later means adding one line
    | here plus the class itself, not a new cron entry and a new settings page.
    |
    | Do NOT pre-register a task type here that has no real handler behind it
    | yet (task-scheduler.md section 2) — an entry here is what makes a task
    | type real, not just planned.
    |
    */
    'task_types' => [
        'manuscript_generation' => ManuscriptGenerationTaskType::class,
        // Complaint Desk's escalation engine (references/complaint-desk.md
        // section 3) — see App\Support\ScheduledTasks\
        // ComplaintEscalationCheckTaskType's class doc. Its scheduled_tasks
        // row is seeded by a dedicated migration (system-owned, always
        // enabled), not created lazily through a settings form.
        'complaint_escalation_check' => ComplaintEscalationCheckTaskType::class,
        // Push notification reliability sweep (mobile-push-notifications
        // build notes) — App\Support\ScheduledTasks\PushReceiptCheckTaskType.
        // System-owned, seeded the same way as complaint_escalation_check
        // above (2026_08_26_040020_seed_push_receipt_check_scheduled_task).
        'push_receipt_check' => PushReceiptCheckTaskType::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | manuscript_generation chunking
    |--------------------------------------------------------------------------
    |
    | How many customers each Bus::batch() chunk job computes per run
    | (task-scheduler.md section 4.1 — "200-500 per chunk, tune to this app's
    | real per-customer computation cost, don't guess a number and never
    | revisit it"). 250 splits SWECOM's real ~549-customer dataset into 3
    | chunks — enough to prove real parallel-chunking/partial-failure
    | tolerance without being so small that a full run is mostly queue
    | overhead. Overridable per-environment/in tests (a small value here lets
    | feature tests exercise multi-chunk behavior without needing hundreds of
    | fixture rows).
    |
    */
    'manuscript_generation' => [
        'chunk_size' => (int) env('MANUSCRIPT_GENERATION_CHUNK_SIZE', 250),
    ],

];
