<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) log of platform-level landlord actions —
 * creating/approving/rejecting/updating tenants, maintenance windows,
 * granting/revoking landlord access, tenant imports. Distinct from each
 * tenant's own `audit_logs`, which only ever see that tenant's data.
 * Append-only: App\Models\LandlordActivity::record() is the only writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlord_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 30)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->string('summary', 255);
            $table->jsonb('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_activities');
    }
};
