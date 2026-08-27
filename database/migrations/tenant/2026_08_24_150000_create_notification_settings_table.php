<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-row-per-tenant settings table — same shape as `companies`
     * (see database/migrations/tenant/2026_08_19_090544_create_companies_table.php
     * and App\Models\Company::cached()). Per-channel booleans all default
     * false: unlike `companies`, tenants must explicitly opt in to each
     * notification channel rather than getting it enabled by default. The
     * Twilio credential columns are `text` (not `string`) because Laravel's
     * `encrypted` Eloquent cast (see App\Models\NotificationSetting) stores
     * a base64-encoded, versioned ciphertext envelope that runs well past a
     * typical varchar length — a real SID/token is short, but the encrypted
     * form is not.
     */
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->boolean('whatsapp_enabled')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('sms_enabled')->default(false);
            $table->text('twilio_account_sid')->nullable();
            $table->text('twilio_auth_token')->nullable();
            $table->text('twilio_whatsapp_number')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
