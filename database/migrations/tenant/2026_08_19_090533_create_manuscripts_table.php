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
        Schema::create('manuscripts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->decimal('bill', 12, 2);
            $table->decimal('total_arrears', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->decimal('total_bill', 12, 2)->default(0);
            $table->date('payment_expiration')->nullable();
            $table->string('period', 7)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_manuscripts_uuid');
            $table->index('customer_id', 'idx_manuscripts_customer');
            $table->index('period', 'idx_manuscripts_period');
            $table->index(['customer_id', 'period'], 'idx_manuscripts_customer_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manuscripts');
    }
};
