<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price options for a service (owner, 2026-10-27): besides its default
 * price, a service may offer a short list of other prices to pick from when
 * registering a customer (e.g. TV at 2,000 / 2,500 / 3,000, each with an
 * optional label), and a minimum below which no one may set a customer's
 * price. Base service only (not its options/channels) for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // [{amount: "2000.00", label: "Neighbourhood"}, ...]
            $table->jsonb('price_options')->nullable();
            $table->decimal('min_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['price_options', 'min_price']);
        });
    }
};
