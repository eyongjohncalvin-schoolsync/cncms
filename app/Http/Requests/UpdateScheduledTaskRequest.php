<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CommandRun;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Settings > Command Runs' schedule-config form (task-scheduler.md section
 * 4's admin UI — a day-of-month picker for manuscript_generation). Gated via
 * CommandRunPolicy::manageSchedule() (same reuse-not-duplicate rationale as
 * that policy method's own doc comment) rather than a dedicated
 * ScheduledTask policy — this app has exactly one admin-configurable task
 * type today, and CommandRun is already the settings-page-facing model this
 * whole feature hangs off of.
 */
class UpdateScheduledTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSchedule', CommandRun::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            // 1-31 accepted at the form layer; ManuscriptGenerationTaskType::isDue()
            // clamps a day beyond the current month's real length down to
            // that month's last day (task-scheduler.md section 4) rather
            // than this validation rejecting, e.g., "31" outright.
            'day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
        ];
    }
}
