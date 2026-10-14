<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expense controls (Resources phase 1):
     *
     * - suppliers — who gets paid. A supplier someone adds starts
     *   `pending` until a second person approves it (ghost suppliers).
     * - expenditures gain an approval life cycle (status), where the money
     *   came from (payment_method + payment_ref, unique), supplier, branch,
     *   receipt evidence (sha256 for duplicate detection + capture
     *   metadata), anomaly flags, the service the cost belongs to (NULL =
     *   shared), and void/reject/approve stamps.
     *   Existing rows become `approved` — they were trusted when recorded.
     * - recurring_expenses — templates for rent, salaries, electricity…
     *   confirmed once per month (one expense per template per period).
     * - companies gain the approval / evidence limits (Settings).
     *
     * Two database rules make the record tamper-resistant regardless of
     * the application code: an approved or voided expense can't have its
     * money fields changed, and no expense row can ever be deleted (it is
     * voided instead, keeping the trail).
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('momo_number', 30)->nullable();
            $table->string('location', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX suppliers_name_unique ON suppliers (lower(name))');
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_status_check CHECK (status IN ('pending', 'active', 'inactive'))");
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id)');
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_approved_by_foreign FOREIGN KEY (approved_by) REFERENCES public.users(id)');

        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->string('name', 120);
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->nullable();
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_day_check CHECK (day_of_month BETWEEN 1 AND 28)');
        DB::statement('ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id)');

        Schema::table('expenditures', function (Blueprint $table) {
            // Existing rows were trusted when recorded → approved.
            $table->string('status', 30)->default('approved');
            $table->boolean('auto_approved')->default(false);
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_ref', 60)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('reference_no', 60)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            // The service this cost belongs to (TV, internet…); NULL = shared
            // across services (rent, salaries) and spread by income share.
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->foreignId('recurring_expense_id')->nullable()->constrained('recurring_expenses')->restrictOnDelete();
            $table->string('recurring_period', 7)->nullable();
            $table->char('receipt_sha256', 64)->nullable();
            $table->jsonb('receipt_meta')->nullable();
            $table->jsonb('flags')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('device_recorded_at')->nullable();
            $table->unsignedBigInteger('first_approved_by')->nullable();
            $table->timestampTz('first_approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->index(['status', 'spent_at'], 'idx_expenditures_status_spent');
            $table->index('receipt_sha256', 'idx_expenditures_receipt_sha256');
            $table->unique(['recurring_expense_id', 'recurring_period'], 'expenditures_recurring_period_unique');
        });

        // New expenses must earn their status through ExpenditureService.
        DB::statement("ALTER TABLE expenditures ALTER COLUMN status SET DEFAULT 'pending_approval'");
        DB::statement("ALTER TABLE expenditures ADD CONSTRAINT expenditures_status_check CHECK (status IN ('pending_evidence', 'pending_approval', 'pending_second_approval', 'approved', 'rejected', 'void'))");
        DB::statement("ALTER TABLE expenditures ADD CONSTRAINT expenditures_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('cash', 'mtn_momo', 'orange_money', 'bank'))");
        DB::statement('CREATE UNIQUE INDEX expenditures_payment_ref_unique ON expenditures (lower(payment_ref)) WHERE payment_ref IS NOT NULL');

        foreach (['first_approved_by', 'approved_by', 'rejected_by', 'voided_by'] as $column) {
            DB::statement("ALTER TABLE expenditures ADD CONSTRAINT expenditures_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES public.users(id)");
        }

        // Approved / voided expenses: the money facts are frozen.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION expenditures_guard_update() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('approved', 'void') AND (
                    NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.category_id IS DISTINCT FROM OLD.category_id
                    OR NEW.spent_at IS DISTINCT FROM OLD.spent_at
                    OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
                    OR NEW.payment_method IS DISTINCT FROM OLD.payment_method
                    OR NEW.payment_ref IS DISTINCT FROM OLD.payment_ref
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id
                ) THEN
                    RAISE EXCEPTION 'An approved expense cannot be changed; void it and record a new one.';
                END IF;

                IF OLD.status = 'void' AND NEW.status IS DISTINCT FROM 'void' THEN
                    RAISE EXCEPTION 'A voided expense cannot be reinstated.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER expenditures_guard_update BEFORE UPDATE ON expenditures
                FOR EACH ROW EXECUTE FUNCTION expenditures_guard_update();

            CREATE OR REPLACE FUNCTION expenditures_guard_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Expenses are never deleted; void them instead.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER expenditures_guard_delete BEFORE DELETE ON expenditures
                FOR EACH ROW EXECUTE FUNCTION expenditures_guard_delete();
        SQL);

        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('expense_auto_approve_below', 12, 2)->default(5000);
            $table->decimal('expense_second_approval_above', 12, 2)->default(50000);
            $table->decimal('expense_receipt_required_from', 12, 2)->default(2000);
            $table->decimal('expense_supplier_required_from', 12, 2)->default(10000);
            $table->unsignedSmallInteger('expense_backdate_days')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'expense_auto_approve_below', 'expense_second_approval_above', 'expense_receipt_required_from',
                'expense_supplier_required_from', 'expense_backdate_days',
            ]);
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS expenditures_guard_delete ON expenditures;
            DROP FUNCTION IF EXISTS expenditures_guard_delete();
            DROP TRIGGER IF EXISTS expenditures_guard_update ON expenditures;
            DROP FUNCTION IF EXISTS expenditures_guard_update();
        SQL);

        DB::statement('DROP INDEX IF EXISTS expenditures_payment_ref_unique');
        DB::statement('ALTER TABLE expenditures DROP CONSTRAINT IF EXISTS expenditures_status_check');
        DB::statement('ALTER TABLE expenditures DROP CONSTRAINT IF EXISTS expenditures_payment_method_check');

        foreach (['first_approved_by', 'approved_by', 'rejected_by', 'voided_by'] as $column) {
            DB::statement("ALTER TABLE expenditures DROP CONSTRAINT IF EXISTS expenditures_{$column}_foreign");
        }

        Schema::table('expenditures', function (Blueprint $table) {
            $table->dropUnique('expenditures_recurring_period_unique');
            $table->dropIndex('idx_expenditures_status_spent');
            $table->dropIndex('idx_expenditures_receipt_sha256');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('service_id');
            $table->dropConstrainedForeignId('recurring_expense_id');
            $table->dropColumn([
                'status', 'auto_approved', 'payment_method', 'payment_ref', 'reference_no', 'recurring_period',
                'receipt_sha256', 'receipt_meta', 'flags', 'device_recorded_at', 'first_approved_by', 'first_approved_at',
                'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'voided_by', 'voided_at', 'void_reason',
            ]);
        });

        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('suppliers');
    }
};
