<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * RBAC v2 Wave 3 (docs/plans/rbac-v2-configurable-roles.md, "Users
     * Control Center"): drop the CHECK constraint the original
     * 2026_08_19_090723_create_tenant_users_table migration's
     * `$table->enum('role', [...])` produced on Postgres
     * (`tenant_users_role_check`, pinning `role` to the 5 system names).
     *
     * Custom roles created via the Wave 3 Roles & Permissions matrix carry
     * arbitrary lowercase names, and `tenant_users.role` stores that name
     * verbatim (the plan deliberately keeps that column a plain string, not
     * a FK — see the create-roles-tables migration's docblock). A DB-level
     * enum can't express "any current row in this tenant's `roles` table",
     * so validation moves entirely to the request layer:
     * Store/UpdateTenantUserRequest now use `Rule::exists('roles', 'name')`
     * against the tenant `roles` table instead of `Rule::in([5 literals])`.
     *
     * No replacement FK is added: a cross-column deferred FK to
     * `roles(name)` buys little over the request-layer `exists` check
     * (role deletion is already blocked by RoleController::destroy when any
     * membership still holds the role), and this codebase's convention is
     * to validate this kind of reference in FormRequests anyway (see
     * StoreTenantUserRequest's existing `branch_uuid` note).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE tenant_users DROP CONSTRAINT IF EXISTS tenant_users_role_check');
    }

    public function down(): void
    {
        // Re-pin to the 5 system names. Any custom-role membership row would
        // violate this — acceptable for a rollback (the Wave 3 UI that
        // creates such rows is gone too in that scenario).
        DB::statement(
            'ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_role_check '.
            "CHECK (role::text = ANY (ARRAY['super','admin','manager','agent','worker']::text[]))"
        );
    }
};
