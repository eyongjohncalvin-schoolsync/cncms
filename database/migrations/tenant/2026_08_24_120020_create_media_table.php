<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * spatie/laravel-medialibrary's standard `media` table (stub copied
     * from vendor/spatie/laravel-medialibrary/database/migrations/
     * create_media_table.php.stub — that package ships no vendor:publish
     * "migrations" tag for this version, so this is hand-copied rather
     * than published).
     *
     * Deliberately placed under database/migrations/tenant/ (run per
     * tenant schema via `php artisan tenants:migrate`), NOT the central
     * database/migrations/ directory. Every current use of Media Library
     * in this app (Company::logo) is tenant-scoped data — each tenant's
     * company/logo belongs only to that tenant's schema, mirroring every
     * other tenant-owned table here (companies, customers, payments,
     * ...). If a *central* model ever needs media (e.g. something on the
     * landlord's own Tenant model), that would need its own separate
     * `media` table migration under database/migrations/ — polymorphic
     * `model_type`/`model_id` alone doesn't make this table naturally
     * central, and Stancl's schema-per-tenant setup means a single
     * physical `media` table can't be safely shared across both central
     * and tenant models without also carrying a tenant discriminator that
     * this schema-per-tenant design doesn't need.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();

            $table->nullableTimestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
