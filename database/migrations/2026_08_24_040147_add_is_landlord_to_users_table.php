<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) platform-authority flag — see
 * app/Http/Middleware/EnsureLandlord.php's docblock for the full
 * reasoning. This replaces a previous check that hard-coded "role=super
 * on tenant 'swecom'" as a stand-in for landlord access — wrong because
 * `tenant_users.role` is a per-tenant attribute by design (confirmed
 * against stancl/tenancy's own docs, which model a synced `role` column
 * as deliberately NOT central), so a tenant-scoped role can never
 * correctly answer a platform-wide question. `is_landlord` lives here
 * instead, on the one table that's genuinely central regardless of which
 * tenant (if any) is active.
 *
 * `granted_by`/`granted_at` are a lightweight audit trail for this
 * specific flag (who escalated whom, when) — a full central audit_logs
 * table wasn't built for this since it's a single, rare, high-privilege
 * action expected to be granted a handful of times total, not a
 * high-volume mutation stream like the tenant-scoped audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_landlord')->default(false)->after('status');
            $table->foreignId('landlord_granted_by')->nullable()->after('is_landlord')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('landlord_granted_at')->nullable()->after('landlord_granted_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landlord_granted_by');
            $table->dropColumn(['is_landlord', 'landlord_granted_at']);
        });
    }
};
