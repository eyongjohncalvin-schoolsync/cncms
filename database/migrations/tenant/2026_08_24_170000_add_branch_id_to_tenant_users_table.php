<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch RBAC — see
 * .ai/skills/cncms/cncms-context/references/branches-and-locations.md
 * section 4 (Access control). Nullable by design, not a three-step
 * nullable-then-backfill-then-NOT-NULL migration like zones.branch_id
 * (2026_08_24_160010_add_branch_id_to_zones_table.php): `null` here is a
 * meaningful, permanent value ("sees every branch"), not a transient
 * pre-backfill state. Every existing tenant_users row gets `branch_id =
 * null` on migration day, i.e. full cross-branch access — nobody's access
 * silently narrows on deploy. Branch-fencing a staff member is an opt-in
 * admin action afterward (Settings/Users.tsx).
 *
 * nullOnDelete() (not restrictOnDelete() like zones.branch_id): deleting a
 * branch that a staff member happens to be fenced to should not be blocked
 * by that assignment — the safe failure direction is the fenced user
 * becoming unrestricted again (branch_id reverts to null), not an admin
 * being unable to delete a branch because someone's account still points
 * at it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('role')->constrained('branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
