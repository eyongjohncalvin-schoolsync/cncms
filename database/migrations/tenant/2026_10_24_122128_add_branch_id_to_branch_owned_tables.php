<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches v2 P1 — the data spine (docs/plans/branches-v2.md §4.2, §6, §7).
 *
 * Every branch-owned row carries its own indexed branch_id, stamped when the
 * row is created (App\Models\Concerns\BelongsToBranch) and never changed, so
 * fencing and per-branch reports are one indexed condition instead of joins
 * through customer → zone, and history stays with the branch where it
 * happened.
 *
 *  - customers / agents: their zone's branch — their CURRENT branch. The
 *    database keeps it equal to the zone's: composite FK (zone_id, branch_id)
 *    → zones (id, branch_id) ON UPDATE CASCADE, so moving a zone to another
 *    branch moves its customers and agents with it.
 *  - customer-owned history (payments, manuscripts, charges, subscriptions,
 *    field registrations, free months, adjustments, opening balances,
 *    messages, complaints): the customer's branch when the row was created.
 *    Plain FK to branches — never re-derived.
 *  - payment_verifications / payment_receipts: the payment's branch.
 *  - cash_movements / cash_day_closes / technician_fee_payouts: the account's.
 *  - remittances, receipt_blocks, field_devices: the person's branch.
 *  - bill_batch_files: the zone's (null for all-zones / ZIP files);
 *    audit_logs: the audited row's (null when it has none). Both nullable.
 *
 * Pure child rows always read through their parent stay without a column:
 * voucher_approvals, remittance_lines, receipt_leaves, complaint_escalations,
 * notification_recipients.
 *
 * Backfill is set-based from the parents (an archive imported from before
 * this migration is filled the same way); people with no zone fall back to
 * headquarters — nobody is fenced to a branch yet.
 */
return new class extends Migration
{
    /** @var list<string> branch_id NOT NULL once backfilled */
    private const array REQUIRED = [
        'customers', 'agents',
        'payments', 'manuscripts', 'customer_charges', 'customer_service', 'field_installations',
        'free_month_grants', 'arrears_adjustments', 'opening_balance_entries', 'messages', 'complaints',
        'payment_verifications', 'payment_receipts',
        'cash_movements', 'cash_day_closes', 'technician_fee_payouts',
        'remittances', 'receipt_blocks', 'field_devices',
    ];

    /** @var list<string> */
    private const array OPTIONAL = ['bill_batch_files', 'audit_logs'];

    /** @var list<string> built nullable by 2026_10_24_105749, tightened here */
    private const array MONEY_OUT = ['vouchers', 'expenditures', 'cash_accounts'];

    /** @var list<string> */
    private const array CUSTOMER_HISTORY = [
        'payments', 'manuscripts', 'customer_charges', 'customer_service', 'field_installations',
        'free_month_grants', 'arrears_adjustments', 'opening_balance_entries', 'messages',
    ];

    public function up(): void
    {
        foreach ([...self::REQUIRED, ...self::OPTIONAL] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();

                if (! in_array($tableName, ['customers', 'agents'], true)) {
                    $table->index('branch_id');
                }
            });
        }

        $this->backfill();

        foreach ([...self::REQUIRED, ...self::MONEY_OUT] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN branch_id SET NOT NULL");
        }

        // A customer's / agent's branch is always its zone's.
        Schema::table('zones', function (Blueprint $table) {
            $table->unique(['id', 'branch_id'], 'zones_id_branch_unique');
        });

        foreach (['customers', 'agents'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_zone_branch_foreign FOREIGN KEY (zone_id, branch_id) REFERENCES zones (id, branch_id) ON UPDATE CASCADE ON DELETE RESTRICT");

            Schema::table($tableName, function (Blueprint $table) {
                $table->index('branch_id');
            });
        }

        // The hot per-branch queries: a month's bills, payments by date, a day's cash.
        Schema::table('manuscripts', fn (Blueprint $table) => $table->index(['branch_id', 'period']));
        Schema::table('payments', fn (Blueprint $table) => $table->index(['branch_id', 'created_at']));
        Schema::table('cash_movements', fn (Blueprint $table) => $table->index(['branch_id', 'business_date']));
        Schema::table('expenditures', fn (Blueprint $table) => $table->index(['branch_id', 'spent_at']));
    }

    public function down(): void
    {
        Schema::table('expenditures', fn (Blueprint $table) => $table->dropIndex(['branch_id', 'spent_at']));

        foreach (['customers', 'agents'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT {$tableName}_zone_branch_foreign");
        }

        Schema::table('zones', fn (Blueprint $table) => $table->dropUnique('zones_id_branch_unique'));

        foreach (self::MONEY_OUT as $tableName) {
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN branch_id DROP NOT NULL");
        }

        foreach ([...self::REQUIRED, ...self::OPTIONAL] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('branch_id');
            });
        }
    }

    private function backfill(): void
    {
        $headquartersId = (int) DB::table('branches')->where('is_headquarters', true)->value('id');

        DB::statement('UPDATE customers c SET branch_id = z.branch_id FROM zones z WHERE z.id = c.zone_id');
        DB::statement('UPDATE agents a SET branch_id = z.branch_id FROM zones z WHERE z.id = a.zone_id');

        foreach (self::CUSTOMER_HISTORY as $tableName) {
            DB::statement("UPDATE {$tableName} t SET branch_id = c.branch_id FROM customers c WHERE c.id = t.customer_id");
        }

        DB::statement('UPDATE payment_verifications v SET branch_id = p.branch_id FROM payments p WHERE p.id = v.payment_id');
        DB::statement('UPDATE payment_receipts r SET branch_id = p.branch_id FROM payments p WHERE p.id = r.payment_id');

        DB::statement('UPDATE complaints t SET branch_id = COALESCE(
            (SELECT c.branch_id FROM customers c WHERE c.id = t.customer_id),
            (SELECT z.branch_id FROM zones z WHERE z.id = t.zone_id),
            ?)', [$headquartersId]);

        foreach (['cash_movements', 'cash_day_closes', 'technician_fee_payouts'] as $tableName) {
            DB::statement("UPDATE {$tableName} t SET branch_id = a.branch_id FROM cash_accounts a WHERE a.id = t.cash_account_id");
        }

        // A person's branch: an agent's zone; everyone else is headquarters (nobody is fenced yet).
        foreach (['remittances' => 'collector_user_id', 'receipt_blocks' => 'holder_user_id', 'field_devices' => 'user_id'] as $tableName => $userColumn) {
            DB::statement("UPDATE {$tableName} t SET branch_id = COALESCE(
                (SELECT z.branch_id FROM agents a JOIN zones z ON z.id = a.zone_id WHERE a.user_id = t.{$userColumn} ORDER BY a.id LIMIT 1),
                ?)", [$headquartersId]);
        }

        DB::statement('UPDATE bill_batch_files f SET branch_id = z.branch_id FROM zones z WHERE z.id = f.zone_id');
    }
};
