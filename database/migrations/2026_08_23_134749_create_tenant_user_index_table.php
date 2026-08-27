<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) index of which tenant each user belongs to.
 *
 * Populated/kept in sync by App\Observers\TenantUserIndexObserver whenever
 * a tenant-scoped TenantUser row is created/updated/deleted (in ANY
 * tenant's schema). Exists so App\Http\Middleware\ResolveTenant and
 * ResolveTenantWeb can resolve "which tenant does this authenticated user
 * belong to" with a single central-connection query, instead of either
 * hard-coding one tenant (the previous approach — see those classes' git
 * history) or scanning every tenant schema's tenant_users table per
 * request once there's more than a handful of tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id');
            $table->string('role', 20);
            $table->timestamps();

            $table->unique(['user_id', 'tenant_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user_index');
    }
};
