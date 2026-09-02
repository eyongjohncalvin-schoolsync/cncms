<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

/**
 * Command Runs (audit log of manuscript:calculate — and, since
 * task-scheduler.md, any scheduled task's — executions), surfaced under
 * Settings which is admin-only per web-admin-spec.md's nav spec ("SETTINGS
 * [admin only]").
 */
class CommandRunPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('command_runs.view');
    }

    /**
     * Publishing a `pending_review` scheduled run (task-scheduler.md
     * section 4's admin UI) is gated to the same roles as
     * ManuscriptPolicy::calculate() ("whatever role already triggers
     * manual runs today") — deliberately the same check, not a new one, so
     * the two never drift apart.
     */
    public function publish(User $user): bool
    {
        return $this->context->can('command_runs.publish');
    }

    /**
     * Editing a scheduled task's enabled/schedule_config (the Settings UI's
     * day-of-month picker) — same admin-only gate as viewing this page at
     * all; there's no separate "can view but not configure" role here.
     */
    public function manageSchedule(User $user): bool
    {
        return $this->context->can('command_runs.schedule');
    }
}
