<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) one-time 6-digit codes sent by email
 * (App\Services\SecurityCodeService): passwordless login, the "email me a
 * code instead" two-factor fallback, and confirming a new email address
 * (`email` holds that new address). Only an HMAC of the code is stored.
 * Single use (consumed_at), short-lived (expires_at) and attempt-limited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('code_hash', 64);
            $table->string('email')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_codes');
    }
};
