<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A technician's usual zones. Optional, and only a routing hint: a
 * technician with no zones works anywhere in their reach, and any complaint
 * can still go to anyone — these zones only decide who is suggested first
 * when a complaint is assigned. Unlike agents' zones (zone_agents), they
 * grant no access to anything.
 *
 * user_id is the central users.id (cross-schema, no FK); the zone keeps its
 * branch through the same composite key as zone_agents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_zones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('zone_id');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            // Central users.id of whoever set it (cross-schema, no FK).
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'zone_id']);
            $table->index('zone_id');
        });

        DB::statement('ALTER TABLE technician_zones ADD CONSTRAINT technician_zones_zone_branch_foreign FOREIGN KEY (zone_id, branch_id) REFERENCES zones (id, branch_id) ON UPDATE CASCADE ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_zones');
    }
};
