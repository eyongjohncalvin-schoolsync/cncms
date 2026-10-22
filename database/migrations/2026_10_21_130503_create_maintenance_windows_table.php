<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) platform maintenance windows, scheduled by a
 * landlord (App\Http\Controllers\Landlord\MaintenanceWindowController).
 * While a window is active every tenant user is held on the maintenance
 * page (web) or gets a 503 MAINTENANCE (API) — see App\Support\
 * MaintenanceMode. Central, not per-tenant: maintenance (a deploy, the
 * swecom data cutover) is platform-wide.
 *
 * A window is never deleted: `cancelled_at` (called off before it began)
 * and `ended_at` (finished early) keep the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();

            $table->index(['ends_at', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
