<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /complaints/{complaint}/resolve. ComplaintPolicy::resolve() enforces
 * both the role gate (super/admin/manager) AND the "never the submitter"
 * rule (references/complaint-desk.md section 3) — this class only adds the
 * data-shape rule: `resolution_notes` is required non-empty even though the
 * column is nullable (nullable so reopen() can clear it back to null).
 */
class ResolveComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resolve', $this->route('complaint'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resolution_notes' => ['required', 'string'],
        ];
    }
}
