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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('zone_id')->constrained('zones')->restrictOnDelete();
            // user_id is a cross-schema FK to the central public.users table — see note below.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 50);
            $table->string('location', 50);
            $table->string('phone', 20);
            $table->decimal('salary', 12, 2);
            $table->string('email', 50)->nullable();
            $table->date('dob')->nullable();
            $table->enum('marital_status', ['yes', 'no'])->nullable();
            $table->integer('children')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('picture')->nullable();
            $table->string('sync_token')->nullable()->unique();
            $table->timestampTz('last_sync_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_agents_uuid');
            $table->index('zone_id', 'idx_agents_zone');
            $table->index('user_id', 'idx_agents_user');
        });

        // Partial index — Postgres-specific (WHERE clause), no Blueprint-fluent equivalent.
        DB::statement('CREATE INDEX idx_agents_sync ON agents (sync_token) WHERE sync_token IS NOT NULL');

        // Cross-schema FK: see the same note in the payment_verifications migration —
        // tenant search_path does not implicitly include `public`, so the target is
        // schema-qualified explicitly in a raw statement.
        DB::statement('ALTER TABLE agents ADD CONSTRAINT agents_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
