<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money must balance at every level (owner, 2026-10-28), wave 3 — accounts:
 *
 *  - cash_shortages — every franc missing is owned by a named person until
 *    someone else settles it (repaid / written off): a till's day-close
 *    count, a handover or branch transfer received short, a refused deposit,
 *    a safe count. Close shortages already booked become open rows now.
 *  - cash_transfers — money into another person's till (a handover, e.g. the
 *    manager's pickup) waits for that person to accept what they counted; a
 *    bank deposit (slip number + photo) waits for N confirmers, never the
 *    depositor. Out of the sender's account when sent; the open row is the
 *    money in transit.
 *  - branch_transfers.received_amount — what the receiving branch counted.
 *  - cash_statement_counts — each month, the statement (bank, wallet) or the
 *    count (safe) against the book.
 *  - companies.deposit_confirmations — how many people confirm a deposit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shortages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedBigInteger('owner_user_id');
            $table->string('kind', 30);
            $table->decimal('amount', 12, 2);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->string('settled_note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['status', 'owner_user_id'], 'idx_cash_shortages_status_owner');
            $table->index(['source_type', 'source_id'], 'idx_cash_shortages_source');
        });

        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('number', 30)->unique();
            $table->string('kind', 20);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('from_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('to_user_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('received_amount', 12, 2)->nullable();
            $table->string('status', 20)->default('sent');
            $table->string('slip_number', 100)->nullable();
            $table->string('slip_photo_path')->nullable();
            $table->string('slip_photo_hash', 64)->nullable();
            $table->unsignedSmallInteger('required_confirmations')->default(0);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('sent_by');
            $table->timestampTz('sent_at');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['status', 'kind'], 'idx_cash_transfers_status_kind');
        });

        // A slip is used once per bank account; a photo never twice.
        DB::statement("CREATE UNIQUE INDEX uq_cash_transfers_slip ON cash_transfers (to_account_id, lower(slip_number)) WHERE kind = 'deposit' AND status <> 'rejected'");
        DB::statement("CREATE UNIQUE INDEX uq_cash_transfers_photo ON cash_transfers (slip_photo_hash) WHERE slip_photo_hash IS NOT NULL AND status <> 'rejected'");

        Schema::create('cash_transfer_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_transfer_id')->constrained('cash_transfers')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['cash_transfer_id', 'user_id'], 'uq_cash_transfer_confirmations');
        });

        Schema::create('cash_statement_counts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('period', 7);
            $table->decimal('book_balance', 12, 2);
            $table->decimal('statement_balance', 12, 2);
            $table->decimal('difference', 12, 2);
            $table->string('photo_path')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('counted_by');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['cash_account_id', 'period'], 'uq_cash_statement_counts');
        });

        foreach ([
            'cash_shortages' => ['owner_user_id', 'settled_by', 'created_by'],
            'cash_transfers' => ['to_user_id', 'sent_by', 'decided_by'],
            'cash_transfer_confirmations' => ['user_id'],
            'cash_statement_counts' => ['counted_by'],
        ] as $tableName => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
            }
        }

        Schema::table('branch_transfers', function (Blueprint $table) {
            $table->decimal('received_amount', 12, 2)->nullable();
        });

        DB::statement("UPDATE branch_transfers SET received_amount = amount WHERE status = 'received'");

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('deposit_confirmations')->default(2);
        });

        // Close shortages booked so far had no owner: the till's holder owns them now.
        DB::statement(<<<'SQL'
            INSERT INTO cash_shortages (branch_id, owner_user_id, kind, amount, source_type, source_id, note, created_by, created_at, updated_at)
            SELECT a.branch_id, a.holder_user_id, 'till_close', m.amount, 'App\Models\CashMovement', m.id,
                   'Day close of ' || m.business_date::text || ' (before shortages were owned)', m.recorded_by, m.created_at, m.created_at
            FROM cash_movements m
            JOIN cash_accounts a ON a.id = m.cash_account_id
            WHERE m.kind = 'close_adjustment' AND m.direction = 'out' AND a.kind = 'till' AND a.holder_user_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('deposit_confirmations');
        });

        Schema::table('branch_transfers', function (Blueprint $table) {
            $table->dropColumn('received_amount');
        });

        Schema::dropIfExists('cash_statement_counts');
        Schema::dropIfExists('cash_transfer_confirmations');
        Schema::dropIfExists('cash_transfers');
        Schema::dropIfExists('cash_shortages');
    }
};
