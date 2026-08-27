<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) per-user language preference — see
 * .ai/skills/cncms/cncms-context/references/language-support.md section 4.
 * Nullable: most staff simply inherit the tenant's `companies.default_locale`
 * (see the sibling tenant migration) via App\Http\Middleware\ResolveLocale's
 * resolution order. Lives on the central `users` table (not per-tenant)
 * because it's a personal, cross-device preference, consistent with how
 * `is_landlord` already lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
