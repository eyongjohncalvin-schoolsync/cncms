<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money balance follow-ups (owner, 2026-10-30):
 *
 *  - vouchers.payee_user_id — a bon paid to a member of staff, chosen from
 *    the staff list: they never approve or pay it, and an advance they
 *    don't account for is their shortage. Null = someone outside (typed name).
 *  - Salary advances: a month's salary may be paid in parts, so one person
 *    and month may have several salary bons. Their total never passes the
 *    approved salary — VoucherService::requestSalary() checks it under a lock
 *    instead of the old one-bon-per-month unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('payee_user_id')->nullable();
        });

        DB::statement('ALTER TABLE vouchers ADD CONSTRAINT vouchers_payee_user_id_foreign FOREIGN KEY (payee_user_id) REFERENCES public.users(id)');
        DB::statement('DROP INDEX IF EXISTS uq_vouchers_salary_month');
        DB::statement('CREATE INDEX idx_vouchers_salary_month ON vouchers (salary_user_id, salary_period) WHERE salary_user_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_vouchers_salary_month');
        DB::statement("CREATE UNIQUE INDEX uq_vouchers_salary_month ON vouchers (salary_user_id, salary_period) WHERE salary_user_id IS NOT NULL AND status NOT IN ('rejected', 'cancelled')");

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('payee_user_id');
        });
    }
};
