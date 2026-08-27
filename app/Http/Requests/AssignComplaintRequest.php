<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Complaint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /complaints/{complaint}/assign. Purely metadata — see Complaint's
 * class doc — but the future escalation engine's Level 0 audience
 * (references/complaint-desk.md section 3) reads assigned_to, so this is
 * kept behind the same office-tier gate as linkDuplicate() rather than left
 * unbuilt for this pass.
 */
class AssignComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', Complaint::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignee_uuid' => ['required', 'uuid'],
        ];
    }
}
