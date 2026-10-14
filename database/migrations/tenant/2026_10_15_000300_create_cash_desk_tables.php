<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash desk (Resources phase 2). Every franc has one holder at every
     * moment:
     *
     * - cash_accounts — a till per person who handles cash (created on
     *   first use), the office safe, petty cash, the bank and the MTN /
     *   Orange mobile-money accounts. `balance` is a cache of the ledger,
     *   updated in the same transaction as every ledger row and re-checked
     *   nightly.
     * - cash_ledger — append-only. Every movement in or out of an account
     *   (a cash collection, a cash expense, a handover, a deposit, a close
     *   difference) is one row, never edited or deleted (a mistake is a
     *   reversing row). Each row carries the SHA-256 of the previous row, so
     *   any change made behind the application's back breaks the chain.
     * - cash_handovers — cash moving between two people: the giver starts
     *   it, the receiver confirms what they counted. Until then the cash is
     *   still the giver's responsibility.
     * - cash_closes — the daily blind count by denomination; the system's
     *   expected figure is only revealed after counting. Differences are
     *   approved by someone else and posted to the ledger.
     * - cash_deposits — banking cash (or moving it to a MoMo account) with
     *   the slip photo, verified by a second person.
     * - accounting_periods — closed months: nothing can be dated inside a
     *   locked month afterwards.
     */
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('type', 20);
            $table->string('name', 120);
            $table->unsignedBigInteger('holder_user_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('balance', 14, 2)->default(0);
            $table->decimal('max_hold', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['type', 'is_active'], 'idx_cash_accounts_type');
        });

        DB::statement("ALTER TABLE cash_accounts ADD CONSTRAINT cash_accounts_type_check CHECK (type IN ('till', 'safe', 'petty_cash', 'bank', 'mtn_momo', 'orange_money'))");
        DB::statement('ALTER TABLE cash_accounts ADD CONSTRAINT cash_accounts_holder_foreign FOREIGN KEY (holder_user_id) REFERENCES public.users(id)');
        // One personal till per person.
        DB::statement("CREATE UNIQUE INDEX cash_accounts_one_till_per_user ON cash_accounts (holder_user_id) WHERE type = 'till'");

        Schema::create('cash_ledger', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('direction', 3);
            $table->decimal('amount', 14, 2);
            $table->string('type', 30);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at')->useCurrent();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('cash_ledger')->restrictOnDelete();
            $table->string('period', 7);
            $table->string('note', 255)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->index(['account_id', 'occurred_at'], 'idx_cash_ledger_account_time');
            $table->index(['source_type', 'source_id'], 'idx_cash_ledger_source');
            $table->index('period', 'idx_cash_ledger_period');
        });

        DB::statement("ALTER TABLE cash_ledger ADD CONSTRAINT cash_ledger_direction_check CHECK (direction IN ('in', 'out'))");
        DB::statement('ALTER TABLE cash_ledger ADD CONSTRAINT cash_ledger_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE cash_ledger ADD CONSTRAINT cash_ledger_recorded_by_foreign FOREIGN KEY (recorded_by) REFERENCES public.users(id)');
        // A movement can only be reversed once. (Posting the same source
        // twice is refused inside CashLedgerService's chain lock, which
        // also allows re-posting after a reversal — e.g. a corrected
        // payment amount.)
        DB::statement('CREATE UNIQUE INDEX cash_ledger_reversed_once ON cash_ledger (reverses_id) WHERE reverses_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_ledger_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'The cash ledger is append-only; post a reversing entry instead.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_ledger_no_update BEFORE UPDATE ON cash_ledger
                FOR EACH ROW EXECUTE FUNCTION cash_ledger_immutable();
            CREATE TRIGGER cash_ledger_no_delete BEFORE DELETE ON cash_ledger
                FOR EACH ROW EXECUTE FUNCTION cash_ledger_immutable();
        SQL);

        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('from_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('received_amount', 14, 2)->nullable();
            $table->jsonb('denominations')->nullable();
            $table->string('status', 20)->default('pending');
            $table->char('code', 6);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('initiated_by');
            $table->timestampTz('initiated_at')->useCurrent();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->uuid('local_uuid')->nullable()->unique();
            $table->timestampsTz();

            $table->index(['to_account_id', 'status'], 'idx_cash_handovers_to_status');
            $table->index(['from_account_id', 'status'], 'idx_cash_handovers_from_status');
        });

        DB::statement("ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_status_check CHECK (status IN ('pending', 'confirmed', 'short', 'cancelled'))");
        DB::statement('ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_distinct_accounts CHECK (from_account_id <> to_account_id)');
        DB::statement('ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_initiated_by_foreign FOREIGN KEY (initiated_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_confirmed_by_foreign FOREIGN KEY (confirmed_by) REFERENCES public.users(id)');

        Schema::create('cash_closes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->date('business_date');
            $table->decimal('expected', 14, 2);
            $table->decimal('counted', 14, 2);
            $table->decimal('variance', 14, 2);
            $table->jsonb('denominations');
            $table->string('status', 20);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('closed_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['account_id', 'business_date'], 'cash_closes_once_per_day');
            $table->index(['status', 'business_date'], 'idx_cash_closes_status');
        });

        DB::statement("ALTER TABLE cash_closes ADD CONSTRAINT cash_closes_status_check CHECK (status IN ('balanced', 'pending', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE cash_closes ADD CONSTRAINT cash_closes_closed_by_foreign FOREIGN KEY (closed_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE cash_closes ADD CONSTRAINT cash_closes_decided_by_foreign FOREIGN KEY (decided_by) REFERENCES public.users(id)');

        Schema::create('cash_deposits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('from_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reference', 80)->nullable();
            $table->string('slip_path')->nullable();
            $table->char('slip_sha256', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('deposited_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestampsTz();

            $table->index('status', 'idx_cash_deposits_status');
        });

        DB::statement("ALTER TABLE cash_deposits ADD CONSTRAINT cash_deposits_status_check CHECK (status IN ('pending', 'confirmed', 'rejected'))");
        DB::statement('ALTER TABLE cash_deposits ADD CONSTRAINT cash_deposits_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE cash_deposits ADD CONSTRAINT cash_deposits_deposited_by_foreign FOREIGN KEY (deposited_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE cash_deposits ADD CONSTRAINT cash_deposits_decided_by_foreign FOREIGN KEY (decided_by) REFERENCES public.users(id)');
        DB::statement('CREATE UNIQUE INDEX cash_deposits_reference_unique ON cash_deposits (lower(reference)) WHERE reference IS NOT NULL');

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('period', 7)->unique();
            $table->string('status', 10)->default('locked');
            $table->unsignedBigInteger('locked_by');
            $table->timestampTz('locked_at')->useCurrent();
            $table->string('note', 255)->nullable();
        });

        DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_locked_by_foreign FOREIGN KEY (locked_by) REFERENCES public.users(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('cash_deposits');
        Schema::dropIfExists('cash_closes');
        Schema::dropIfExists('cash_handovers');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cash_ledger_no_update ON cash_ledger;
            DROP TRIGGER IF EXISTS cash_ledger_no_delete ON cash_ledger;
            DROP FUNCTION IF EXISTS cash_ledger_immutable();
        SQL);

        Schema::dropIfExists('cash_ledger');
        Schema::dropIfExists('cash_accounts');
    }
};
