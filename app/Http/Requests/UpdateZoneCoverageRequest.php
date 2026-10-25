<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One change on the Zone coverage screen (App\Services\ZoneCoverageService::apply()):
 * who becomes responsible for, helps in, or leaves which zones of a branch.
 */
class UpdateZoneCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Agent::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_uuid' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['responsible', 'helper', 'remove', 'clear'])],
            'agent_uuid' => ['required_unless:action,clear', 'nullable', 'uuid'],
            'zone_uuids' => ['required', 'array', 'min:1', 'max:500'],
            'zone_uuids.*' => ['uuid', 'distinct'],
            'keep_previous_as_helper' => ['sometimes', 'boolean'],
        ];
    }
}
