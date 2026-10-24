<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A zone's town is its branch's (branches v2: a branch is a town), so zones
 * stop asking for one. Existing values stay; new zones no longer default to
 * "KUMBA 3".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table): void {
            $table->string('town', 25)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table): void {
            $table->string('town', 25)->nullable(false)->default('KUMBA 3')->change();
        });
    }
};
