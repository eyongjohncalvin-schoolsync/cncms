<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant default language — see
 * .ai/skills/cncms/cncms-context/references/language-support.md section 4.
 * Paired with the fields company-settings.md already added this cycle;
 * living on `companies` (not a separate `tenant_settings` table) means it
 * inherits branch-scoping for free once branches-and-locations.md ships.
 * App\Http\Middleware\ResolveLocale falls back to this when the
 * authenticated user has no personal `users.locale` set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('default_locale', 5)->nullable()->default('en')->after('niu');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('default_locale');
        });
    }
};
