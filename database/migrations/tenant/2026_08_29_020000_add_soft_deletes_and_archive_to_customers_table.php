<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer archiving (customer-deletion deliberation, 2026-08-29 — the
     * 3-agent synthesis). A customer with billing history can no longer be
     * hard-deleted: `payments`/`manuscripts`/`messages`/`arrears_adjustments`
     * .customer_id are all restrictOnDelete(), and destroying that history
     * would contradict "keep it auditable". Instead the customer is
     * soft-deleted (archived): every child row stays physically in place and
     * queryable, the customer drops out of active lists / manuscript runs /
     * dashboards (Laravel's SoftDeletes global scope), and Archive ⇄ Restore
     * is reversible indefinitely.
     *
     * This is the FIRST use of SoftDeletes anywhere in the schema — every
     * other model (Payment especially) is documented as deliberately not
     * soft-deleting. The report/P&L aggregates (App\Services\ReportService,
     * ResourcesDashboardService) deliberately use raw `->join('customers')`
     * which bypasses the global scope, so closed-period register totals and
     * closed-month P&L stay byte-for-byte reproducible after an archive.
     *
     * `archived_by` / `archived_reason` live on the row (not only in
     * audit_logs) so the Restore banner and the ?archived=1 list can render
     * "archived on / by / reason" without an audit-log join. `archived_by`
     * references the central `users` table (users live in the `public`
     * schema, customers in the tenant schema) — a cross-schema reference, so
     * no DB-level FK, matching how `audit_logs.user_id` and other actor
     * columns in tenant tables already carry a central user id unconstrained.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('archived_by')->nullable()->after('deleted_at');
            $table->text('archived_reason')->nullable()->after('archived_by');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['deleted_at', 'archived_by', 'archived_reason']);
        });
    }
};
