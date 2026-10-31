<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device (IP address) blocks (App\Services\LoginGuard): a landlord's
 * "Unblock device" sets `ip_cleared_at` so those failures stop counting
 * against the address — separate from `cleared_at`, which a correct
 * password sets for the account and which must never reset the address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_failures', function (Blueprint $table) {
            $table->timestampTz('ip_cleared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('login_failures', function (Blueprint $table) {
            $table->dropColumn('ip_cleared_at');
        });
    }
};
