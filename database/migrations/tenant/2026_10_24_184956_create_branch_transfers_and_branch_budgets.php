<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 P5 (docs/plans/branches-v2.md §4.7, D3, D6): money between
 * branches.
 *
 *  - branch_transfers: money a branch sends to another (usually
 *    headquarters). Sending takes it out of the sender's account at once;
 *    while `sent` it is in transit — visible to both branches and to HQ;
 *    the receiving branch puts it into one of its accounts (`received`) or
 *    refuses it, which returns it to the sender (`rejected`). Never income
 *    or expense.
 *  - branches.sends_to_hq: how often a branch is expected to send money to
 *    headquarters (off / weekly / monthly) — drives the "not sent" alert.
 *  - budgets.branch_id: a branch's own monthly budgets (null = the company's).
 *    The unique key becomes (category, period, branch) — one company budget
 *    and one per branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('number', 30)->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('sent');
            $table->string('note', 500)->nullable();
            // Cross-schema to central public.users — no FKs (same as agents.user_id).
            $table->unsignedBigInteger('sent_by');
            $table->timestampTz('sent_at');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->string('receive_note', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['from_branch_id', 'status']);
            $table->index(['to_branch_id', 'status']);
        });

        DB::statement("ALTER TABLE branch_transfers ADD CONSTRAINT branch_transfers_status CHECK (status IN ('sent', 'received', 'rejected'))");
        DB::statement('ALTER TABLE branch_transfers ADD CONSTRAINT branch_transfers_two_branches CHECK (from_branch_id <> to_branch_id)');
        DB::statement('ALTER TABLE branch_transfers ADD CONSTRAINT branch_transfers_amount CHECK (amount > 0)');

        Schema::table('branches', function (Blueprint $table) {
            $table->string('sends_to_hq', 10)->default('off');
        });

        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_sends_to_hq CHECK (sends_to_hq IN ('off', 'weekly', 'monthly'))");

        Schema::table('budgets', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->dropUnique(['category_id', 'period']);
        });

        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_category_period_branch_unique UNIQUE NULLS NOT DISTINCT (category_id, period, branch_id)');

        // Each branch has its own MoMo / Orange wallet (D3: no joint account):
        // one active wallet per provider per branch, not per company.
        DB::statement('DROP INDEX IF EXISTS uq_cash_accounts_wallet_provider');
        DB::statement("CREATE UNIQUE INDEX uq_cash_accounts_wallet_provider ON cash_accounts (provider, branch_id) WHERE kind = 'mobile_money' AND is_active");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_cash_accounts_wallet_provider');
        DB::statement("CREATE UNIQUE INDEX uq_cash_accounts_wallet_provider ON cash_accounts (provider) WHERE kind = 'mobile_money' AND is_active");

        DB::statement('ALTER TABLE budgets DROP CONSTRAINT budgets_category_period_branch_unique');
        DB::table('budgets')->whereNotNull('branch_id')->delete();

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->unique(['category_id', 'period']);
        });

        DB::statement('ALTER TABLE branches DROP CONSTRAINT branches_sends_to_hq');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('sends_to_hq');
        });

        Schema::dropIfExists('branch_transfers');
    }
};
