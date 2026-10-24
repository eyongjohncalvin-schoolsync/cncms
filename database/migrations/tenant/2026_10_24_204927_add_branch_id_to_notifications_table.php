<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 D12: a notification about one branch's bon, complaint,
 * handover or adjustment reaches that branch's staff (and the unfenced owner,
 * HQ-level staff and investors) — never another branch's manager. branch_id
 * is the branch it is about; null = company-wide (everyone in its audience).
 * Existing rows stay null (company-wide), as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
