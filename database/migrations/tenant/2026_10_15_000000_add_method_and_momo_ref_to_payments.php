<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a payment was made, captured at collection: cash, MTN MoMo,
     * Orange Money or bank — and the mobile-money transaction reference.
     * Cash payments feed the collector's cash wallet (cash desk); a MoMo
     * reference can only ever be used once, so the same reference can't
     * cover two customers' payments. Older payments stay NULL ("not
     * recorded") and are left out of cash reconciliation.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 20)->nullable();
            $table->string('momo_ref', 50)->nullable();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IS NULL OR method IN ('cash', 'mtn_momo', 'orange_money', 'bank'))");
        DB::statement('CREATE UNIQUE INDEX payments_momo_ref_unique ON payments (lower(momo_ref)) WHERE momo_ref IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_momo_ref_unique');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_method_check');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['method', 'momo_ref']);
        });
    }
};
