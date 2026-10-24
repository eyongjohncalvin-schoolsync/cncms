<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 — closing gaps found by the post-merge audit:
 *
 *  - bill_batches.branch_id: the branch a bill run was generated for (the
 *    generator's fence / switcher view; null = company-wide). A fenced user
 *    only sees, downloads, cancels or deletes their branch's runs.
 *  - customer_branch_moves: a customer moving to another branch is a dated,
 *    recorded event (§4.3, D11) — from / to branch and zone, the first period
 *    the new branch bills, who and why. Written when a customer's branch
 *    changes (a relocation into another branch's zone).
 *  - one ACTIVE till per person (was: one till per person ever), so a cashier
 *    transferred to another branch gets a new till there while the old one,
 *    emptied and closed, stays in the old branch's books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_batches', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
        });

        Schema::create('customer_branch_moves', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->foreignId('to_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            // D11: the new branch bills from the next period.
            $table->string('effective_period', 7);
            // Cross-schema to central public.users — no FK (same as agents.user_id).
            $table->unsignedBigInteger('moved_by')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['customer_id', 'id']);
            $table->index(['to_branch_id', 'effective_period']);
        });

        DB::statement('DROP INDEX IF EXISTS uq_cash_accounts_till_holder');
        DB::statement("CREATE UNIQUE INDEX uq_cash_accounts_till_holder ON cash_accounts (holder_user_id) WHERE kind = 'till' AND is_active");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_cash_accounts_till_holder');
        DB::statement("CREATE UNIQUE INDEX uq_cash_accounts_till_holder ON cash_accounts (holder_user_id) WHERE kind = 'till'");

        Schema::dropIfExists('customer_branch_moves');

        Schema::table('bill_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
