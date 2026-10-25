<?php

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prepayment offers: "4 months for 10,000" — months paid ahead at a
 * promotional price. Headquarters keeps the list; a branch may set its own
 * price for an offer (branch_prices, like services). A payment made under an
 * offer records it and buys exactly its months for exactly its price, so
 * nobody has to record a bigger amount to make the months come out right.
 *
 * Also grants the new `payments.offers` permission to the existing `admin`
 * system role (DefaultRolesSeeder gives admin every permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepayment_offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 60);
            $table->unsignedSmallInteger('months');
            $table->decimal('price', 12, 2);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE prepayment_offers ADD CONSTRAINT prepayment_offers_months_check CHECK (months >= 2)');
        DB::statement('ALTER TABLE prepayment_offers ADD CONSTRAINT prepayment_offers_price_check CHECK (price > 0)');

        Schema::table('branch_prices', function (Blueprint $table): void {
            $table->foreignId('prepayment_offer_id')->nullable()->constrained('prepayment_offers')->cascadeOnDelete();
        });
        // Still exactly one target per row, now four kinds.
        DB::statement('ALTER TABLE branch_prices DROP CONSTRAINT branch_prices_one_target');
        DB::statement('ALTER TABLE branch_prices ADD CONSTRAINT branch_prices_one_target CHECK (num_nonnulls(service_id, service_variant_id, installation_offer_id, prepayment_offer_id) = 1)');

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('prepayment_offer_id')->nullable()->constrained('prepayment_offers')->nullOnDelete();
        });

        $adminRoleIds = DB::table('roles')->where('name', 'admin')->where('is_system', true)->pluck('id');

        foreach ($adminRoleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => Permission::PaymentsOffers->value]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', Permission::PaymentsOffers->value)->delete();

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('prepayment_offer_id');
        });

        DB::table('branch_prices')->whereNotNull('prepayment_offer_id')->delete();
        DB::statement('ALTER TABLE branch_prices DROP CONSTRAINT branch_prices_one_target');
        Schema::table('branch_prices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('prepayment_offer_id');
        });
        DB::statement('ALTER TABLE branch_prices ADD CONSTRAINT branch_prices_one_target CHECK (num_nonnulls(service_id, service_variant_id, installation_offer_id) = 1)');

        Schema::dropIfExists('prepayment_offers');
    }
};
