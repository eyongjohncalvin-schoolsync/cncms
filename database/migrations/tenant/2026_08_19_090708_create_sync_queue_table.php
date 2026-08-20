<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_queue', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('device_id');
            $table->enum('direction', ['up', 'down']);
            $table->string('entity_type', 50);
            $table->uuid('entity_uuid')->nullable();
            $table->uuid('local_uuid')->nullable();
            $table->jsonb('payload');
            $table->enum('status', ['pending', 'processing', 'synced', 'conflict', 'failed'])->default('pending');
            $table->smallInteger('attempt_count')->default(0);
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('device_id', 'idx_sync_device');
            $table->index('status', 'idx_sync_status');
            $table->index(['direction', 'status'], 'idx_sync_direction');
            $table->index(['entity_type', 'entity_uuid'], 'idx_sync_entity');
            $table->index('created_at', 'idx_sync_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_queue');
    }
};
