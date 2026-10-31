<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) record of every refused password sign-in, web and
 * mobile (App\Services\LoginGuard): what was typed, which account it
 * matched, from where. Ten wrong passwords in an hour block the account's
 * password sign-in for a while; a correct password or a landlord's Unblock
 * sets `cleared_at` on the rows so they stop counting. Kept 90 days
 * (App\Models\LoginFailure is Prunable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('identifier', 191);
            $table->string('reason', 20);
            $table->string('channel', 10);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('cleared_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['identifier', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_failures');
    }
};
