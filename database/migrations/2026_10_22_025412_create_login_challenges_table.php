<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) pending second-factor logins
 * (App\Services\LoginChallengeService). Created once the first factor
 * (password, Google or an email code) has passed for a user with two-factor
 * on; the user is NOT signed in until the challenge is passed. Only an HMAC
 * of the random token is stored — the web login keeps the plain token in
 * the session, the API hands it to the app. Single use, 10-minute expiry,
 * attempt-limited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('first_factor', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_challenges');
    }
};
