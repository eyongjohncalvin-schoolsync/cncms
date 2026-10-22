<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) authenticator-app two-factor columns on `users`
 * (App\Services\TwoFactorService):
 *   - two_factor_secret: the TOTP secret, Laravel `encrypted` cast. Set
 *     while setting up; 2FA is only ON once two_factor_confirmed_at is set.
 *   - two_factor_recovery_codes: JSON list of HMAC hashes of the unused
 *     recovery codes (the plain codes are shown once and never stored).
 *   - two_factor_last_used_step: the last accepted TOTP time step, so one
 *     code can never be replayed inside its validity window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->bigInteger('two_factor_last_used_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_used_step',
            ]);
        });
    }
};
