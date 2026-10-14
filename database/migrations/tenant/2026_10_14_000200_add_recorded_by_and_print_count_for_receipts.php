<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipt improvements:
     * - payments.recorded_by — the staff member who recorded the payment, so
     *   a receipt can say who collected it. Nullable: older payments never
     *   captured it. Cross-schema FK into public.users like every other
     *   user column on tenant tables.
     * - payment_receipts.print_count — how many times staff have printed /
     *   downloaded the receipt; every print after the first is stamped
     *   "DUPLICATE".
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('recorded_by')->nullable();
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_recorded_by_foreign FOREIGN KEY (recorded_by) REFERENCES public.users(id) ON DELETE SET NULL');

        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->unsignedInteger('print_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropColumn('print_count');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_recorded_by_foreign');
            $table->dropColumn('recorded_by');
        });
    }
};
