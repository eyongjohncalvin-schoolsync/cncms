<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central per-user light/dark choice for the website
 * (docs/plans/mobile-manager-app.md, wave 7) — `light`, `dark`, or null
 * for "match my device". On the central `users` table beside `locale`: a
 * personal preference that follows the person to any browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ui_theme', 10)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui_theme');
        });
    }
};
