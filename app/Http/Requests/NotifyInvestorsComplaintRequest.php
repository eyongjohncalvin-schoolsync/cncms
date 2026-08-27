<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Complaint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /complaints/{complaint}/notify-investors — the Level 3 human gate's
 * actual trigger (references/complaint-desk.md section 3). No body;
 * ComplaintPolicy::notifyInvestors() (super/admin only — narrower than
 * resolve()/reopen()) is the entire authorization check. See
 * App\Services\ComplaintEscalationService::notifyInvestors() for the
 * separate 48h-armed business rule this doesn't duplicate here.
 */
class NotifyInvestorsComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('notifyInvestors', Complaint::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
