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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('zone_id')->constrained('zones')->restrictOnDelete();
            $table->string('name', 50);
            $table->string('location', 30)->nullable();
            $table->decimal('bill', 12, 2);
            $table->decimal('others', 12, 2)->default(0);
            $table->string('phone', 20)->nullable();
            $table->text('description')->nullable();
            $table->enum('level', ['normal', 'Vip', 'Operator'])->default('normal');
            $table->enum('status', ['active', 'passive', 'disconnected', 'suspended'])->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_customers_uuid');
            $table->index('zone_id', 'idx_customers_zone');
            $table->index('status', 'idx_customers_status');
            $table->index('level', 'idx_customers_level');
            $table->index('name', 'idx_customers_name');
        });

        // Partial index — Postgres-specific (WHERE clause), no Blueprint-fluent equivalent.
        DB::statement('CREATE INDEX idx_customers_phone ON customers (phone) WHERE phone IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
