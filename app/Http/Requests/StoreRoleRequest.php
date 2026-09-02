<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a custom tenant role (RBAC v2 Wave 3). `name` is the immutable
 * identity `tenant_users.role` will point at — lowercase, slug-ish, unique
 * within the tenant, and never one of the 5 reserved system names. `label`
 * is the free-text display name. `clone_from` optionally seeds the new
 * role's permission set from an existing role (by uuid).
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('roles.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => mb_strtolower(trim($this->input('name')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:50',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::notIn(['super', 'admin', 'manager', 'agent', 'worker']),
                // Tenant `roles` table (default connection is the tenant one
                // inside this request) — rejects a collision with any system
                // OR previously-created custom role.
                Rule::unique('roles', 'name'),
            ],
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'clone_from' => ['nullable', 'string', Rule::exists('roles', 'uuid')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'The role key may only contain lowercase letters, numbers, hyphens and underscores.',
            'name.not_in' => 'That name is reserved for a built-in role.',
        ];
    }

    /**
     * The role to clone permissions from, or null. Resolved here so the
     * controller stays thin.
     */
    public function cloneSource(): ?Role
    {
        $uuid = $this->input('clone_from');

        return $uuid ? Role::query()->where('uuid', $uuid)->first() : null;
    }
}
