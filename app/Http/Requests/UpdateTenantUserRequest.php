<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TenantUser;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tenantUser'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 'sometimes' on both: the Users & Roles page submits these
            // independently (the role <select> patches {role} alone, the
            // job title editor patches {job_title} alone), so neither can be
            // unconditionally 'required' here.
            // RBAC v2 Wave 3: configurable roles — the name must exist in
            // this tenant's `roles` table (system or custom). See
            // StoreTenantUserRequest's identical rule.
            'role' => ['sometimes', 'required', 'string', Rule::exists('roles', 'name')],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:60'],
            // Multi-branch RBAC — the Branch <select> on the Users Control
            // Center Users page patches this alone, same "each control patches its own field"
            // pattern as role/job_title. null clears the fence back to
            // unrestricted (see StoreTenantUserRequest's doc comment).
            'branch_uuid' => ['sometimes', 'nullable', 'string'],
            // The narrow per-user payment-recording grant — see
            // PaymentPolicy::create()'s doc comment and the
            // add_can_record_payments_to_tenant_users_table migration.
            // authorize() above already restricts this whole request to
            // users.manage; this rule additionally rejects (not silently
            // ignores) an attempt to set it on any row whose role isn't
            // currently `worker` — the flag is meaningless for every other
            // role, which already has payments.create via role. Checked
            // against the route-bound TenantUser's CURRENT role: this
            // checkbox is only ever submitted alone (the Users Control
            // Center page patches one field per request, same
            // convention as role/job_title/branch_uuid above), so there is
            // no simultaneous role change to account for here.
            'can_record_payments' => [
                'sometimes', 'boolean',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $tenantUser = $this->route('tenantUser');

                    if ($tenantUser instanceof TenantUser && $tenantUser->role !== 'worker') {
                        $fail('The payment-recording flag can only be set for a worker.');
                    }
                },
            ],
            // The Investor tier grant (references/rbac-permissions.md
            // section 7, App\Policies\ReportPolicy::view()'s doc comment) —
            // unlike can_record_payments above, this is deliberately NOT
            // restricted to any one `role`: it's a pure additive OR that
            // grants exactly one capability (viewing /reports) regardless
            // of the row's role, so no role-mismatch rule is needed here.
            // authorize() above already restricts this whole request to
            // super/admin, matching who may grant it.
            'is_investor' => ['sometimes', 'boolean'],
        ];
    }
}
