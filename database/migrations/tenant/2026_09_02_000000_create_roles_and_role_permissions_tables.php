<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RBAC v2 foundation (docs/plans/rbac-v2-configurable-roles.md, "Data
     * model"). Two tenant-schema tables:
     *
     *   roles            — the 5 seeded system roles ('super','admin',
     *                       'manager','agent','worker') plus any custom
     *                       role an admin adds later via the Wave 3 UI.
     *   role_permissions — the single pivot; one row per (role, permission)
     *                      the role grants. `permission` is validated in the
     *                      app layer against App\Auth\Permission::values()
     *                      (no DB enum/check — the catalog is code, and a
     *                      check constraint would need a migration every
     *                      time the catalog grows).
     *
     * `tenant_users.role` stays a plain string holding `roles.name` — NOT
     * migrated to a FK here (deliberate, per the plan doc: keeps every
     * existing membership row valid and TenantContext::role working with
     * zero data migration; the request layer validates the name).
     *
     * `name` is a plain `string` + unique index, NOT Postgres `citext` as
     * the plan doc's data-model sketch first suggested: this codebase has
     * no `citext` usage anywhere and enabling the extension per tenant
     * schema is a heavier footprint than the problem needs. Role names are
     * normalised to lowercase in the app layer instead (App\Models\Role +
     * the Wave 3 StoreRoleRequest) — the 5 system names are already
     * lowercase, so nothing changes for them.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));

            // Stable identity + display. `name` is the key `tenant_users.role`
            // points at and is locked once set for a system role; `label` is
            // the free-text display name the UI shows and lets an admin edit.
            $table->string('name', 50)->unique();
            $table->string('label', 100);
            $table->string('description', 255)->nullable();

            // The 5 seeded roles: cannot be deleted or renamed(name), but
            // their permission rows CAN be edited (except super — see below).
            $table->boolean('is_system')->default(false);

            // Exactly one row, the Gate::before bypass. Its role_permissions
            // rows are ignored entirely (TenantContext::isSuper() short-
            // circuits), so the seed leaves it with none.
            $table->boolean('is_super')->default(false);

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_roles_uuid');
            $table->index('name', 'idx_roles_name');
        });

        // Hard guarantee of the "exactly one is_super row" invariant the
        // whole bypass depends on — a partial unique index over the single
        // value `true` (multiple `false`/`is_super=0` rows are fine).
        DB::statement('CREATE UNIQUE INDEX uq_roles_single_super ON roles (is_super) WHERE is_super');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission', 100);

            // (role_id, permission) is the natural key — a role either
            // grants a permission or it doesn't, no duplicates, no surrogate id.
            $table->primary(['role_id', 'permission']);
            $table->index('permission', 'idx_role_permissions_permission');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
