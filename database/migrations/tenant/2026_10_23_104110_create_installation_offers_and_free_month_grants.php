<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installation fees, phase 2 (docs/plans/installation-and-promotions.md):
 * installation offers, free months (promotions & compensation), the
 * customer's connection date / first billed month, and the installation
 * refund.
 *
 *  - companies.max_free_months — the cap on months per grant (D8, default 3).
 *  - installation_offers (+ installation_offer_services) — Settings →
 *    Installation offers: a fee and free months PER SERVICE (D7). Copied
 *    onto the customer at creation (the charge's standard_amount and the
 *    grants' months), so a later offer edit never changes a past customer.
 *  - customers.connected_on / billing_starts_period — D4 ("Option X"): the
 *    month the customer joins is not billed.
 *  - free_month_grants — one row per (customer, service) per request,
 *    rows of one request share group_uuid. Free months run by calendar
 *    (D5) from start_period for `months` months, so the billing engine
 *    reads them statelessly — nothing is decremented on the manuscript.
 *    Zero-value: never income, arrears, credit or deferred revenue.
 *  - customer_charges: the offer link, the fee-override approval and the
 *    "Refund installation" request (D9), paid out through a bon.
 *  - manuscripts.free_value — what the free services would have cost this
 *    month (foregone revenue, reporting only, never in the ledger).
 *  - opening_balance_entries.free_months — mid-promo customers at go-live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_free_months')->default(3);
        });

        Schema::create('installation_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->decimal('fee', 12, 2);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('CREATE UNIQUE INDEX uq_installation_offers_name_ci ON installation_offers (lower(name))');
        DB::statement('CREATE UNIQUE INDEX uq_installation_offers_single_default ON installation_offers (is_default) WHERE is_default');
        DB::statement('ALTER TABLE installation_offers ADD CONSTRAINT installation_offers_fee_check CHECK (fee >= 0)');

        Schema::create('installation_offer_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_offer_id')->constrained('installation_offers')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->unsignedSmallInteger('free_months');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['installation_offer_id', 'service_id'], 'uq_installation_offer_services_offer_service');
        });

        DB::statement('ALTER TABLE installation_offer_services ADD CONSTRAINT installation_offer_services_months_check CHECK (free_months BETWEEN 1 AND 36)');

        Schema::table('customers', function (Blueprint $table) {
            $table->date('connected_on')->nullable();
            $table->string('billing_starts_period', 7)->nullable();
        });

        Schema::table('customer_charges', function (Blueprint $table) {
            $table->foreignId('installation_offer_id')->nullable()->constrained('installation_offers')->nullOnDelete();
            // A fee below the offer's (standard_amount) needs admin/owner
            // approval (D8): pending → approved | rejected (rejected puts the
            // standard fee back).
            $table->string('approval_status', 20)->nullable();
            $table->string('approval_rejection_reason', 500)->nullable();

            $table->string('refund_status', 20)->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('refund_reason', 500)->nullable();
            $table->unsignedBigInteger('refund_requested_by')->nullable();
            $table->timestampTz('refund_requested_at')->nullable();
            $table->unsignedBigInteger('refund_decided_by')->nullable();
            $table->timestampTz('refund_decided_at')->nullable();
            $table->string('refund_rejection_reason', 500)->nullable();
            $table->foreignId('refund_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
        });

        DB::statement("ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_approval_status_check CHECK (approval_status IS NULL OR approval_status IN ('pending', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_refund_status_check CHECK (refund_status IS NULL OR refund_status IN ('pending', 'approved', 'rejected'))");

        foreach (['refund_requested_by', 'refund_decided_by'] as $column) {
            DB::statement("ALTER TABLE customer_charges ADD CONSTRAINT customer_charges_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }

        Schema::create('free_month_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('group_uuid');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // Null = every service the customer holds.
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->foreignId('customer_charge_id')->nullable()->constrained('customer_charges')->nullOnDelete();
            $table->foreignId('installation_offer_id')->nullable()->constrained('installation_offers')->nullOnDelete();
            $table->foreignId('arrears_adjustment_id')->nullable()->constrained('arrears_adjustments')->nullOnDelete();
            $table->string('reason', 30);
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('months');
            // What the offer gives for this service — more than this needs
            // approval (D8). Null for compensation / opening balances.
            $table->unsignedSmallInteger('standard_months')->nullable();
            $table->string('status', 40);
            $table->string('start_period', 7)->nullable();

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['customer_id', 'status'], 'idx_free_month_grants_customer_status');
            $table->index('group_uuid', 'idx_free_month_grants_group');
            $table->index(['status', 'start_period'], 'idx_free_month_grants_status_start');
        });

        DB::statement("ALTER TABLE free_month_grants ADD CONSTRAINT free_month_grants_reason_check CHECK (reason IN ('installation_offer', 'compensation', 'opening_balance'))");
        DB::statement("ALTER TABLE free_month_grants ADD CONSTRAINT free_month_grants_status_check CHECK (status IN ('awaiting_installation_payment', 'pending_approval', 'approved', 'rejected', 'cancelled'))");
        DB::statement('ALTER TABLE free_month_grants ADD CONSTRAINT free_month_grants_months_check CHECK (months BETWEEN 1 AND 36)');
        DB::statement("ALTER TABLE free_month_grants ADD CONSTRAINT free_month_grants_approved_has_start CHECK (status <> 'approved' OR start_period IS NOT NULL)");

        foreach (['requested_by', 'decided_by'] as $column) {
            DB::statement("ALTER TABLE free_month_grants ADD CONSTRAINT free_month_grants_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }

        Schema::table('manuscripts', function (Blueprint $table) {
            $table->decimal('free_value', 12, 2)->default(0);
        });

        Schema::table('opening_balance_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('free_months')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('opening_balance_entries', fn (Blueprint $table) => $table->dropColumn('free_months'));
        Schema::table('manuscripts', fn (Blueprint $table) => $table->dropColumn('free_value'));
        Schema::dropIfExists('free_month_grants');

        Schema::table('customer_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_voucher_id');
            $table->dropConstrainedForeignId('installation_offer_id');
            $table->dropColumn([
                'approval_status', 'approval_rejection_reason', 'refund_status', 'refund_amount', 'refund_reason',
                'refund_requested_by', 'refund_requested_at', 'refund_decided_by', 'refund_decided_at', 'refund_rejection_reason',
            ]);
        });

        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['connected_on', 'billing_starts_period']));
        Schema::dropIfExists('installation_offer_services');
        Schema::dropIfExists('installation_offers');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('max_free_months'));
    }
};
