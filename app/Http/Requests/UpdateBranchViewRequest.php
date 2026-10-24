<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The branch switcher (branches v2 §8). Only someone who is NOT fenced to a
 * branch may choose which branch to view; a fenced user's branch is fixed.
 */
class UpdateBranchViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! app(TenantContext::class)->isFenced();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_uuid' => ['nullable', 'uuid', 'exists:branches,uuid'],
        ];
    }
}
