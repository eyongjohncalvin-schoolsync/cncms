<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VIP customers are not billed unless someone ticks "Bill this VIP"
 * (owner, 2026-11-01 — as in the old system). The column defaults to
 * false, so every existing VIP stops being billed from the next monthly
 * run; it means nothing for normal and Operator customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('vip_billed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('vip_billed');
        });
    }
};
