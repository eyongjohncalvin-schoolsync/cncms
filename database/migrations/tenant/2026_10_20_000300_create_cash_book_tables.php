<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash controls wave 4 (docs/plans/cash-controls.md) — the cash book.
     *
     * - cash_accounts — every place company money sits: a cashier's till
     *   (one per cashier, created on first use), the safe, bank accounts and
     *   the two mobile-money wallets.
     * - cash_movements — the ledger. One row per money in/out of one
     *   account; a transfer is two rows. Never updated or deleted: a mistake
     *   is corrected by a reversal row (reversal_of_id). An account's balance
     *   is the sum of its rows.
     * - remittances + remittance_lines — the daily income form: a collector
     *   hands over what they collected; each payment can be handed over
     *   exactly once (payment_id UNIQUE). The cashier counts the cash, ticks
     *   each mobile-money line off the wallet statement, and the variance is
     *   kept; a shortage stays open until repaid or written off.
     * - cash_day_closes — a till's end-of-day count against what the ledger
     *   expects. A closed day refuses new movements until reopened with a
     *   reason.
     * - companies.cash_controls_start_date — payments recorded before cash
     *   controls went live are never asked for in a handover.
     */
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->enum('kind', ['till', 'safe', 'bank', 'mobile_money']);
            $table->string('name', 100);
            $table->string('provider', 20)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->unsignedBigInteger('holder_user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['kind', 'is_active'], 'idx_cash_accounts_kind');
        });

        DB::statement('ALTER TABLE cash_accounts ADD CONSTRAINT cash_accounts_holder_user_id_foreign FOREIGN KEY (holder_user_id) REFERENCES public.users(id)');
        DB::statement("CREATE UNIQUE INDEX uq_cash_accounts_till_holder ON cash_accounts (holder_user_id) WHERE kind = 'till'");
        DB::statement("CREATE UNIQUE INDEX uq_cash_accounts_wallet_provider ON cash_accounts (provider) WHERE kind = 'mobile_money' AND is_active");

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 12, 2);
            $table->string('kind', 30);
            $table->string('document_number', 20)->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('counterpart_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->date('business_date');
            $table->unsignedBigInteger('recorded_by');
            $table->foreignId('reversal_of_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['cash_account_id', 'business_date'], 'idx_cash_movements_account_date');
        });

        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_recorded_by_foreign FOREIGN KEY (recorded_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_positive CHECK (amount > 0)');

        Schema::create('remittances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('number', 20)->unique();
            $table->unsignedBigInteger('collector_user_id');
            $table->date('business_date');
            $table->enum('status', ['submitted', 'received'])->default('submitted');

            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('expected_mtn', 12, 2)->default(0);
            $table->decimal('expected_orange', 12, 2)->default(0);
            $table->decimal('expected_bank', 12, 2)->default(0);
            $table->decimal('declared_cash', 12, 2)->default(0);
            $table->jsonb('declared_denominations')->nullable();
            $table->string('collector_note', 500)->nullable();
            $table->timestampTz('submitted_at')->useCurrent();

            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->foreignId('till_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->decimal('received_cash', 12, 2)->nullable();
            $table->jsonb('received_denominations')->nullable();
            $table->decimal('cash_variance', 12, 2)->nullable();
            $table->string('receiver_note', 500)->nullable();

            $table->enum('shortage_status', ['open', 'repaid', 'written_off'])->nullable();
            $table->unsignedBigInteger('shortage_settled_by')->nullable();
            $table->timestampTz('shortage_settled_at')->nullable();
            $table->string('shortage_note', 500)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['collector_user_id', 'status'], 'idx_remittances_collector_status');
            $table->index('status', 'idx_remittances_status');
        });

        foreach (['collector_user_id', 'received_by', 'shortage_settled_by'] as $column) {
            DB::statement("ALTER TABLE remittances ADD CONSTRAINT remittances_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }

        Schema::create('remittance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remittance_id')->constrained('remittances')->cascadeOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 12, 2);
            $table->string('reference', 100)->nullable();
            $table->boolean('wallet_confirmed')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('cash_day_closes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->date('business_date');
            $table->decimal('expected', 12, 2);
            $table->decimal('counted', 12, 2);
            $table->jsonb('denominations')->nullable();
            $table->decimal('variance', 12, 2);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['cash_account_id', 'business_date'], 'uq_cash_day_closes_account_date');
        });

        foreach (['closed_by', 'reopened_by'] as $column) {
            DB::statement("ALTER TABLE cash_day_closes ADD CONSTRAINT cash_day_closes_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->date('cash_controls_start_date')->nullable();
        });

        // Today: everything recorded from now on is handed over through the
        // daily income form; earlier payments are history.
        DB::table('companies')->update(['cash_controls_start_date' => now()->toDateString()]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('cash_controls_start_date');
        });

        Schema::dropIfExists('cash_day_closes');
        Schema::dropIfExists('remittance_lines');
        Schema::dropIfExists('remittances');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_accounts');
    }
};
