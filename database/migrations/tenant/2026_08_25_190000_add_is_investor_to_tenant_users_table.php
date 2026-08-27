<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Investor tier — see references/rbac-permissions.md section 7 for the
 * full reasoning. A third minimal, targeted RBAC extension, same shape as
 * `can_record_payments` (section 2): one additive boolean, no new role.
 *
 * Unlike `is_landlord` (central `users`, platform-wide authority), investor
 * authority is inherently tenant-scoped — SWECOM's investor must never see
 * another tenant's reports — so this flag lives on `tenant_users`, not
 * `users`, reusing that table's existing cross-schema-FK convention
 * (`user_id` -> public.users) for `investor_granted_by` below.
 *
 * `default(false)` (not nullable) — matches this table's existing
 * boolean-shaped convention (can_record_payments, and payments.
 * recorded_offline / expenditures.recorded_offline / expense_categories.
 * is_active elsewhere in this schema): a plain non-nullable boolean with an
 * explicit default, not a three-state nullable column. Every existing
 * tenant_users row gets `false` on migration day — nobody gains report
 * access silently on deploy.
 *
 * `investor_granted_by`/`investor_granted_at` mirror `landlord_granted_by`/
 * `landlord_granted_at` on the central `users` table exactly — a
 * lightweight audit trail for who escalated whom, when. `granted_by` is a
 * cross-schema FK to the central `public.users` table: tenant schemas run
 * with a single-schema search_path (see PostgreSQLSchemaManager::
 * makeConnectionConfig), so `public` is not implicitly searched.
 * foreignId()->constrained() has no reliable way to target a table in a
 * different Postgres schema from a Blueprint closure, so the constraint is
 * added via a schema-qualified raw statement referencing public.users(id)
 * directly — same pattern as tenant_users.user_id (create_tenant_users_
 * table) and payment_verifications.verified_by.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->boolean('is_investor')->default(false)->after('can_record_payments');
            $table->unsignedBigInteger('investor_granted_by')->nullable()->after('is_investor');
            $table->timestampTz('investor_granted_at')->nullable()->after('investor_granted_by');

            $table->index('investor_granted_by');
        });

        DB::statement('ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_investor_granted_by_foreign FOREIGN KEY (investor_granted_by) REFERENCES public.users(id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropForeign('tenant_users_investor_granted_by_foreign');
            $table->dropColumn(['is_investor', 'investor_granted_by', 'investor_granted_at']);
        });
    }
};
