<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 foundation (docs/plans/branches-v2.md §5): record which branch
 * money-out belongs to, so v2 has real history to work with. No screen, rule
 * or block reads it yet.
 *
 *  - vouchers.branch_id — the requester's fence (agents: their zone's
 *    branch); an unfenced requester's bon is headquarters'. Set by
 *    App\Services\VoucherService through App\Support\BranchAttribution.
 *  - expenditures.branch_id — copied from the bon that produced it.
 *  - cash_accounts.branch_id — a till carries its holder's branch; the
 *    company wallets, safe and bank accounts are headquarters'.
 *
 * Existing rows go to headquarters: no staff are fenced yet, so everything
 * so far was raised and paid by headquarters. Left nullable until v2 has
 * every writer stamping it.
 */
return new class extends Migration
{
    private const array TABLES = ['vouchers', 'expenditures', 'cash_accounts'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->index('branch_id');
            });
        }

        $headquartersId = DB::table('branches')->where('is_headquarters', true)->value('id');

        if ($headquartersId === null) {
            return;
        }

        foreach (self::TABLES as $tableName) {
            DB::table($tableName)->whereNull('branch_id')->update(['branch_id' => $headquartersId]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('branch_id');
            });
        }
    }
};
