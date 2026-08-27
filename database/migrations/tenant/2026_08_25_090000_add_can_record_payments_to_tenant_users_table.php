<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal, single-purpose RBAC extension — see PaymentPolicy::create()'s doc
 * block. A `worker`-role user cannot record payments by default (that's
 * still governed entirely by `role`, unchanged); this column is an explicit,
 * per-user opt-in a super/admin can grant to ONE worker at a time (e.g. a
 * front-desk "Secretary" who takes walk-in payments), not a general
 * capability-grant mechanism. Deliberately meaningless for every other role
 * (super/admin/manager/agent already have payments.create via role — see
 * UpdateTenantUserRequest's validation, which rejects setting this on a
 * non-worker row).
 *
 * `default(false)` (not nullable) — matches this table's existing boolean-
 * shaped convention isn't set yet, but mirrors payments.recorded_offline /
 * expenditures.recorded_offline / expense_categories.is_active elsewhere in
 * this schema: a plain non-nullable boolean with an explicit default, not a
 * three-state nullable column. Every existing tenant_users row gets `false`
 * on migration day — nobody's access silently widens on deploy.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->boolean('can_record_payments')->default(false)->after('branch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('can_record_payments');
        });
    }
};
