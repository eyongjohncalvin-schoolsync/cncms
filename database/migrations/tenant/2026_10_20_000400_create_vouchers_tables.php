<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash controls wave 5 (docs/plans/cash-controls.md) — vouchers / bons de
     * caisse: every franc that leaves the company goes through one.
     *
     *   request → approve → pay (→ settle, for a cash advance)
     *
     * - approval_tier `standard`: one `vouchers.approve` holder who is not the
     *   requester. `stakeholders`: the amount is above the company's single
     *   voucher limit, or it takes the day's payouts above the daily limit —
     *   then EVERY investor must approve (approval_mode `all`); with no
     *   investors set up, one owner (super) approval (`any`). The required
     *   approvers are snapshotted at request time (required_approver_ids).
     * - voucher_approvals — one row per approver decision (maker ≠ checker,
     *   and nobody approves twice).
     * - Paying writes a cash-book movement out of the paying account and, for
     *   an expense, the Expenditure the Resources dashboard already reports.
     *   An advance is settled later: what was spent becomes the Expenditure,
     *   the change goes back into the till.
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('number', 20)->unique();
            $table->enum('kind', ['expense', 'advance']);
            $table->string('payee_name', 150);
            $table->string('purpose', 500);
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('needed_on');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled', 'paid', 'settled'])->default('pending');

            $table->enum('approval_tier', ['standard', 'stakeholders'])->default('standard');
            $table->enum('approval_mode', ['all', 'any'])->default('any');
            $table->jsonb('tier_reasons')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('required_approver_ids')->default(DB::raw("'[]'::jsonb"));
            $table->string('attachment_path')->nullable();

            $table->unsignedBigInteger('requested_by');
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->foreignId('paid_from_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('received_by_name', 150)->nullable();
            $table->string('signed_copy_path')->nullable();
            $table->foreignId('expenditure_id')->nullable()->constrained('expenditures')->nullOnDelete();

            $table->date('settle_due_on')->nullable();
            $table->decimal('spent_amount', 12, 2)->nullable();
            $table->decimal('returned_amount', 12, 2)->nullable();
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->string('settlement_note', 500)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('status', 'idx_vouchers_status');
            $table->index(['needed_on', 'status'], 'idx_vouchers_needed_on_status');
        });

        foreach (['requested_by', 'rejected_by', 'paid_by', 'settled_by'] as $column) {
            DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }

        DB::statement('ALTER TABLE vouchers ADD CONSTRAINT vouchers_amount_positive CHECK (amount > 0)');

        Schema::create('voucher_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->enum('decision', ['approved', 'rejected']);
            $table->string('note', 500)->nullable();
            $table->timestampTz('decided_at')->useCurrent();

            $table->unique(['voucher_id', 'user_id'], 'uq_voucher_approvals_voucher_user');
        });

        DB::statement('ALTER TABLE voucher_approvals ADD CONSTRAINT voucher_approvals_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_approvals');
        Schema::dropIfExists('vouchers');
    }
};
