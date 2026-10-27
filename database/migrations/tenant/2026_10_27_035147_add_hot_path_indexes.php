<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for filters the query audit (2026-10-27) found unserved. Postgres
 * does not index a foreign key by itself, so these were sequential scans:
 *  - payments (recorded_by, created_at): the hand-over page, the phone's
 *    hand-over preview and the cash-not-handed-over alert;
 *  - payments (customer_id, created_at): "last payment" per customer;
 *  - payments (technician_fee_payout_id): the technician fees page;
 *  - remittance_lines (remittance_id), vouchers (remittance_id),
 *    vouchers (requested_by): every hand-over and its bons;
 *  - audit_logs (table_name, record_id): a record's history;
 *  - receipt_leaves (scanned_unused_at), partial: the receipts-scanned-
 *    before-recording alert.
 * Each is created only when its column exists (IF NOT EXISTS), so a tenant
 * on an older schema still migrates.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3?: string}> table, index, columns, where */
    private const array INDEXES = [
        ['payments', 'idx_payments_recorded_by_created', 'recorded_by, created_at'],
        ['payments', 'idx_payments_customer_created', 'customer_id, created_at'],
        ['payments', 'idx_payments_technician_fee_payout', 'technician_fee_payout_id'],
        ['remittance_lines', 'idx_remittance_lines_remittance', 'remittance_id'],
        ['vouchers', 'idx_vouchers_remittance', 'remittance_id'],
        ['vouchers', 'idx_vouchers_requested_by', 'requested_by'],
        ['audit_logs', 'idx_audit_table_record', 'table_name, record_id'],
        ['receipt_leaves', 'idx_receipt_leaves_scanned_unused', 'scanned_unused_at', "status = 'unused' and scanned_unused_at is not null"],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index) {
            [$table, $name, $columns] = $index;
            $firstColumn = trim(explode(',', $columns)[0]);

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $firstColumn)) {
                continue;
            }

            DB::statement("create index if not exists {$name} on {$table} ({$columns})".(isset($index[3]) ? " where {$index[3]}" : ''));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [, $name]) {
            DB::statement("drop index if exists {$name}");
        }
    }
};
