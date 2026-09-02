<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Auth\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rename a role's label/description and/or replace its full permission set
 * (RBAC v2 Wave 3 matrix save). `name` is never accepted here — a system
 * role's key is immutable and a custom role's key is fixed at creation.
 *
 * The permission list is validated against App\Auth\Permission — the closed
 * catalog IS the "UI can only toggle, never invent" guard (plan doc). An
 * unknown string is a hard 422, not silently dropped, so a stale/buggy
 * client surfaces the problem instead of quietly under-granting.
 *
 * `is_super` roles are blocked upstream by RolePolicy::update() before this
 * request's rules run.
 */
class UpdateRoleRequest extends FormRequest
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
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::in(Permission::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.*.in' => 'Unknown permission :input — not in the permission catalog.',
        ];
    }
}
