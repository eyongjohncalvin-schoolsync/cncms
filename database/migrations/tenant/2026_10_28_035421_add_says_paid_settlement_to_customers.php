<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A late-registered customer who says they paid someone for the connection
 * stays on the cash alerts until a manager settles it (owner, 2026-10-28):
 * the money came in, the customer was mistaken, or it is written off —
 * with a reason, by someone other than whoever connected them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('says_paid_outcome', 20)->nullable();
            $table->string('says_paid_note', 500)->nullable();
            $table->unsignedBigInteger('says_paid_settled_by')->nullable();
            $table->timestampTz('says_paid_settled_at')->nullable();
        });

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_says_paid_settled_by_foreign FOREIGN KEY (says_paid_settled_by) REFERENCES public.users(id)');
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign('customers_says_paid_settled_by_foreign');
            $table->dropColumn(['says_paid_outcome', 'says_paid_note', 'says_paid_settled_by', 'says_paid_settled_at']);
        });
    }
};
