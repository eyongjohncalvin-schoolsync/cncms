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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('credit', 12, 2)->default(0);
            $table->enum('frequency', ['monthly', 'yearly', 'months']);
            $table->date('expiration_date')->nullable();
            $table->integer('months')->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->timestampTz('processed_at')->nullable();
            $table->boolean('recorded_offline')->default(false);
            $table->string('recorded_by_device')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_payments_uuid');
            $table->index('customer_id', 'idx_payments_customer');
            $table->index('verification_status', 'idx_payments_verification');
            $table->index('created_at', 'idx_payments_created');
            $table->index(['customer_id', 'processed_at'], 'idx_payments_customer_processed');
        });

        // Partial index and numeric CHECK constraints — Postgres-specific, no Blueprint-fluent equivalent.
        DB::statement('CREATE INDEX idx_payments_processed ON payments (processed_at) WHERE processed_at IS NOT NULL');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_months_positive CHECK (months > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
