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
        Schema::create('payment_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('receipt_photo_path')->nullable();
            $table->string('momo_ref', 50)->nullable();
            $table->enum('momo_status', ['confirmed', 'failed', 'pending', 'not_checked'])->nullable();
            // verified_by is a cross-schema FK to the central public.users table — see note below.
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('payment_id', 'idx_pv_payment');
            $table->index('status', 'idx_pv_status');
            $table->index('uuid', 'idx_pv_uuid');
            $table->index('verified_by');
        });

        // Cross-schema FK: tenant schemas run with a single-schema search_path (see
        // PostgreSQLSchemaManager::makeConnectionConfig), so `public` is not implicitly
        // searched. foreignId()->constrained() has no reliable way to target a table in a
        // different Postgres schema from a Blueprint closure, so the constraint is added
        // via a schema-qualified raw statement referencing public.users(id) directly.
        DB::statement('ALTER TABLE payment_verifications ADD CONSTRAINT payment_verifications_verified_by_foreign FOREIGN KEY (verified_by) REFERENCES public.users(id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_verifications');
    }
};
