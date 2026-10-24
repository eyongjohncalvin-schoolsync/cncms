<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 P3 (docs/plans/branches-v2.md §4.6, D4): a branch's own price
 * for a service, a service option or an installation offer — inherit from
 * headquarters' catalogue unless overridden, always with a reason (towns
 * with competition may charge less).
 *
 * Append-only: every change is a new row, so the rows are the price history
 * ("who changed Buea's TV price, when, and why"). The branch's current price
 * for a target is its latest row; a row with price NULL hands the target
 * back to headquarters' price. Exactly one target per row (CHECK), each a
 * real foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_prices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->cascadeOnDelete();
            $table->foreignId('service_variant_id')->nullable()->constrained('service_variants')->cascadeOnDelete();
            $table->foreignId('installation_offer_id')->nullable()->constrained('installation_offers')->cascadeOnDelete();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('reason', 255);
            // Cross-schema to central public.users — no FK (same as agents.user_id).
            $table->unsignedBigInteger('set_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // "This branch's current prices": one index range scan, newest first.
            $table->index(['branch_id', 'id']);
        });

        DB::statement('ALTER TABLE branch_prices ADD CONSTRAINT branch_prices_one_target CHECK (num_nonnulls(service_id, service_variant_id, installation_offer_id) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_prices');
    }
};
