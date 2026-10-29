<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money must balance at every level (owner, 2026-10-28), wave 4 — bons:
 *
 *  - vouchers.origin — a bon the system raises: `refund` (money going back
 *    to a customer after a payment was voided), `return_to_collector` (cash
 *    handed in for a payment recorded by mistake), `salary`. A refund or
 *    return bon pays back money already taken off a payment, so it is never
 *    rejected or cancelled — only paid.
 *  - vouchers.salary_user_id + salary_period — a salary bon is one person's
 *    pay for one month: one live bon per person and month.
 *
 * Refund / return bons raised before today are marked from their category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('origin', 30)->nullable();
            $table->unsignedBigInteger('salary_user_id')->nullable();
            $table->string('salary_period', 7)->nullable();
        });

        DB::statement('ALTER TABLE vouchers ADD CONSTRAINT vouchers_salary_user_id_foreign FOREIGN KEY (salary_user_id) REFERENCES public.users(id)');
        DB::statement("CREATE UNIQUE INDEX uq_vouchers_salary_month ON vouchers (salary_user_id, salary_period) WHERE salary_user_id IS NOT NULL AND status NOT IN ('rejected', 'cancelled')");

        DB::statement(<<<'SQL'
            UPDATE vouchers v SET origin = CASE c.name WHEN 'Customer refunds' THEN 'refund' ELSE 'return_to_collector' END
            FROM expense_categories c
            WHERE c.id = v.expense_category_id AND c.name IN ('Customer refunds', 'Returned to collector')
        SQL);
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn(['origin', 'salary_user_id', 'salary_period']);
        });
    }
};
