<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Complaint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /complaints/{complaint}/link-duplicate. See
 * App\Policies\ComplaintPolicy::linkDuplicate() for the manager-tier gate,
 * and App\Services\ComplaintService::linkDuplicate() for why linking
 * excludes the target from its own escalation sweep (see
 * references/complaint-desk.md section 4.2).
 */
class LinkDuplicateComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('linkDuplicate', Complaint::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'duplicate_of_uuid' => ['required', 'uuid'],
        ];
    }
}
