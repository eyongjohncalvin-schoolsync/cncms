<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /complaints/{complaint}/reopen. No body — ComplaintPolicy::reopen()
 * (super/admin/manager, never the submitter — same rule as resolve()) is
 * the entire check. See App\Services\ComplaintService::reopen() for why the
 * 48h escalation clock is never reset by this action.
 */
class ReopenComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reopen', $this->route('complaint'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
