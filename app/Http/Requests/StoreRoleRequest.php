<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a custom tenant role (RBAC v2 Wave 3). Only the display name
 * (`label`) is asked for: the immutable key `tenant_users.role` points at
 * (`roles.name`) is generated on the server from it — see
 * Role::generateUniqueName(). A `name` sent by the client is not validated
 * and never used. `clone_from` optionally seeds the new role's permission
 * set from an existing role (by uuid).
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('roles.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'clone_from' => ['bail', 'nullable', 'string', 'uuid', Rule::exists('roles', 'uuid')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'display name',
            'clone_from' => 'role to copy from',
        ];
    }

    /**
     * The role to clone permissions from, or null. Resolved here so the
     * controller stays thin.
     */
    public function cloneSource(): ?Role
    {
        $uuid = $this->validated('clone_from');

        return $uuid ? Role::query()->where('uuid', $uuid)->first() : null;
    }
}
