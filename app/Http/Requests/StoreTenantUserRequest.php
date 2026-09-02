<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TenantUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TenantUser::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'unique:pgsql.users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:pgsql.users,email'],
            'password' => ['required', 'string', 'min:8'],
            // RBAC v2 Wave 3: roles are configurable — validate the name
            // against this tenant's own `roles` table (system + custom)
            // rather than a hardcoded 5-value list. The DB CHECK constraint
            // that used to pin this column was dropped in
            // 2026_09_03_000000_relax_tenant_users_role_check.
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
            // Purely descriptive — see the tenant_users migration's doc block.
            // Deliberately free text (not Rule::in(...)): real operators will
            // have job titles this app hasn't anticipated.
            'job_title' => ['nullable', 'string', 'max:60'],
            // Multi-branch RBAC (branches-and-locations.md section 4).
            // Omitted/null = unrestricted (sees every branch) — the safe
            // default. Existence and uuid->id resolution happens in
            // SettingsUserController (mirrors CustomerService::
            // resolveZoneId()'s existing "resolve uuid to id, or a
            // ValidationException" pattern) rather than a Rule::exists()
            // here, matching the rest of this codebase's convention. (uuid
            // resolution now lives in UsersControlCenter\UserController.)
            'branch_uuid' => ['nullable', 'string'],
        ];
    }
}
