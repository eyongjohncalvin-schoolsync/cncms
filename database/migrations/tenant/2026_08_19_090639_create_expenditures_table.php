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
        Schema::create('expenditures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            // user_id is a cross-schema FK to the central public.users table — see note below.
            $table->unsignedBigInteger('user_id');
            $table->decimal('amount', 12, 2);
            $table->text('description')->nullable();
            $table->string('receipt_path')->nullable();
            $table->date('spent_at');
            $table->text('notes')->nullable();
            $table->boolean('recorded_offline')->default(false);
            $table->string('recorded_by_device')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_expenditures_uuid');
            $table->index('category_id', 'idx_expenditures_category');
            $table->index('spent_at', 'idx_expenditures_spent');
            $table->index('user_id', 'idx_expenditures_user');
            $table->index(['spent_at', 'category_id'], 'idx_expenditures_spent_category');
        });

        // Numeric CHECK constraint — Postgres-specific, no Blueprint-fluent equivalent.
        DB::statement('ALTER TABLE expenditures ADD CONSTRAINT expenditures_amount_positive CHECK (amount > 0)');

        // Cross-schema FK: see the same note in the payment_verifications migration —
        // tenant search_path does not implicitly include `public`, so the target is
        // schema-qualified explicitly in a raw statement.
        DB::statement('ALTER TABLE expenditures ADD CONSTRAINT expenditures_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenditures');
    }
};
