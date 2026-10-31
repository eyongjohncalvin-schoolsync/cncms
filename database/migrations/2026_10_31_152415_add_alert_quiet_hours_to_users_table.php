<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's quiet hours for phone alerts (docs/plans/mobile-manager-app.md,
 * wave 2): between `alert_quiet_start` and `alert_quiet_end` (HH:MM,
 * Cameroon time, may wrap past midnight) urgent alerts arrive silently;
 * emergencies still ring. Both null = no quiet hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('alert_quiet_start', 5)->nullable();
            $table->string('alert_quiet_end', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['alert_quiet_start', 'alert_quiet_end']);
        });
    }
};
