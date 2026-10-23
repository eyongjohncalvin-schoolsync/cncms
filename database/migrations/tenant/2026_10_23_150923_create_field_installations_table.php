<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installation phase 3 (docs/plans/installation-and-promotions.md, D10):
 * a field agent registers a customer offline and the office activates it.
 *
 *  - customers.activation_status: null = a normal customer; `pending` = a
 *    field registration the office has not activated yet. A pending
 *    customer is never billed (the billing engine reads it as "not started"
 *    for every month) and gets no register rows, bills, reminders or
 *    disconnection eligibility. Rejected registrations are archived.
 *  - field_installations: one row per synced registration — what the agent
 *    proposed (offer, free months per service, connection date), the
 *    possible duplicates found at sync, and the office's decision (who,
 *    when, why, the refund bon on rejection). Idempotent on local_uuid.
 *  - payments.field_installation_id: the money taken with the registration
 *    (installation and months paid upfront). It stays in the collector's
 *    remittance even if the registration is rejected — the receipts were
 *    issued, so the cash is handed over and refunded through a bon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('activation_status', 20)->nullable();
            $table->index('activation_status', 'idx_customers_activation_status');
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_activation_status_check CHECK (activation_status IS NULL OR activation_status IN ('pending'))");

        Schema::create('field_installations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('local_uuid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('customer_charge_id')->nullable()->constrained('customer_charges')->nullOnDelete();
            $table->unsignedBigInteger('submitted_by');
            $table->string('recorded_by_device', 255)->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->jsonb('proposal');
            $table->jsonb('possible_duplicates')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->jsonb('corrections')->nullable();
            $table->foreignId('refund_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('status', 'idx_field_installations_status');
        });

        DB::statement("ALTER TABLE field_installations ADD CONSTRAINT field_installations_status_check CHECK (status IN ('pending', 'activated', 'rejected'))");

        foreach (['submitted_by', 'decided_by'] as $column) {
            DB::statement("ALTER TABLE field_installations ADD CONSTRAINT field_installations_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('field_installation_id')->nullable()->constrained('field_installations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('field_installation_id');
        });

        Schema::dropIfExists('field_installations');

        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_activation_status_check');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('idx_customers_activation_status');
            $table->dropColumn('activation_status');
        });
    }
};
