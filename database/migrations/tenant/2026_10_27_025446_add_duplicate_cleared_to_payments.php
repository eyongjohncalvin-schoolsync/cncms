<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Possible duplicates tab lists every customer with more than one
 * payment in a month (owner, 2026-10-27). When the office has looked and
 * the payments are genuine, "Not a duplicate" stamps them here so they
 * leave the tab. Users are central (public.users): no foreign key, as for
 * payments.reversed_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestampTz('duplicate_cleared_at')->nullable();
            $table->unsignedBigInteger('duplicate_cleared_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['duplicate_cleared_at', 'duplicate_cleared_by']);
        });
    }
};
