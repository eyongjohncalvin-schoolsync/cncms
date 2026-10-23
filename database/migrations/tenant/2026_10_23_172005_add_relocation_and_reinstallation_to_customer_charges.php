<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relocation and re-installation fees (docs/plans/installation-and-promotions.md,
 * "Out of scope / later": same charge model, new kinds).
 *
 *  - customer_charges.kind widens to installation | relocation | reinstallation.
 *    Their money keeps payments.purpose = 'installation' (a one-off service
 *    charge linked through customer_charge_id): billing, arrears, credit and
 *    disconnection already ignore it, remittance already counts it; the
 *    charge's kind tells receipts and reports which fee it was.
 *  - A relocation keeps where the customer moved from and to (zone and
 *    location) and an optional note — the move's entry in the customer's
 *    history.
 *  - companies.relocation_fee / reinstallation_fee — the default fees
 *    (null = no default: the office types the fee each time).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customer_charges DROP CONSTRAINT IF EXISTS customer_charges_kind_check');
        DB::statement("ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_kind_check CHECK (kind IN ('installation', 'relocation', 'reinstallation'))");

        Schema::table('customer_charges', function (Blueprint $table) {
            $table->foreignId('from_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->foreignId('to_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->string('from_location', 255)->nullable();
            $table->string('to_location', 255)->nullable();
            $table->string('note', 500)->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('relocation_fee', 12, 2)->nullable();
            $table->decimal('reinstallation_fee', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['relocation_fee', 'reinstallation_fee']);
        });

        Schema::table('customer_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_zone_id');
            $table->dropConstrainedForeignId('to_zone_id');
            $table->dropColumn(['from_location', 'to_location', 'note']);
        });

        DB::statement('ALTER TABLE customer_charges DROP CONSTRAINT IF EXISTS customer_charges_kind_check');
        DB::statement("ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_kind_check CHECK (kind IN ('installation'))");
    }
};
