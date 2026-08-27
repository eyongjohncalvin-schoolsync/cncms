<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `job_title` is a purely descriptive label (e.g. "Recovery Coordinator",
     * "IT Technician") shown alongside a tenant user's permission `role` —
     * it carries no authorization meaning and is never checked by any
     * Policy or TenantContext::isAnyOf(...) call. Free text, not an enum:
     * real operators will have titles this app hasn't anticipated.
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->string('job_title', 60)->nullable()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('job_title');
        });
    }
};
