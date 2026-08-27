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
     * `companies.logo` was a plain string column that was never actually
     * wired to an upload UI (see Company.tsx's old "Logo upload isn't
     * wired up yet" note). It's replaced by spatie/laravel-medialibrary's
     * `media` table (see the sibling create_media_table migration) — the
     * logo is now an uploaded file on Company's 'logo' media collection,
     * not a stored path string. Every existing row's `logo` is NULL
     * anyway (TenantDatabaseSeeder::seedCompany never set it), so there's
     * no data to migrate.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('logo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('momo_name');
        });
    }
};
