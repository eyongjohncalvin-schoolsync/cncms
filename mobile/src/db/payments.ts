import { getDatabase } from './database';
import type { LocalPayment, SyncStatus } from '../types/db';
import type { SyncPullChangedPayment } from '../types/api';
import { generateUuid } from '../utils/uuid';
import { nowIso, startOfTodayIso } from '../utils/format';

export interface NewLocalPaymentInput {
    customer_uuid: string;
    amount: number;
    credit?: number;
    frequency: 'monthly' | 'yearly' | 'months';
    months?: number | null;
    /** Draw-down Q1 — the agent's "pay down arrears first" toggle. Only
     * meaningful for months/yearly. */
    clear_arrears_first?: boolean;
    /** Local camera file URI, if a receipt photo was captured at submit
     * time — see mobile-app-react-native.md §4's Record Payment section. */
    receipt_local_uri?: string | null;
}

/** Writes a new outbox row for a payment recorded offline-first. Screens
 * call this synchronously on submit — SyncManager picks it up async. See
 * mobile-app-react-native.md §2's outbox/history-view dual-purpose note. */
export async function insertLocalPayment(input: NewLocalPaymentInput): Promise<LocalPayment> {
    const db = await getDatabase();
    const now = nowIso();

    const row: LocalPayment = {
        local_uuid: generateUuid(),
        server_uuid: null,
        customer_uuid: input.customer_uuid,
        amount: input.amount,
        credit: input.credit ?? 0,
        frequency: input.frequency,
        months: input.months ?? null,
        clear_arrears_first: input.clear_arrears_first ?? false,
        verification_status: 'pending',
        rejection_reason: null,
        receipt_local_uri: input.receipt_local_uri ?? null,
        receipt_server_path: null,
        sync_status: 'queued',
        sync_error: null,
        sync_attempts: 0,
        created_at: now,
        updated_at: now,
    };

    await db.runAsync(
        `INSERT INTO payments
            (local_uuid, server_uuid, customer_uuid, amount, credit, frequency, months, clear_arrears_first,
             verification_status, rejection_reason, receipt_local_uri, receipt_server_path,
             sync_status, sync_error, sync_attempts, created_at, updated_at)
         VALUES ($local_uuid, $server_uuid, $customer_uuid, $amount, $credit, $frequency, $months, $clear_arrears_first,
             $verification_status, $rejection_reason, $receipt_local_uri, $receipt_server_path,
             $sync_status, $sync_error, $sync_attempts, $created_at, $updated_at)`,
        {
            $local_uuid: row.local_uuid,
            $server_uuid: row.server_uuid,
            $customer_uuid: row.customer_uuid,
            $amount: row.amount,
            $credit: row.credit,
            $frequency: row.frequency,
            $months: row.months,
            $clear_arrears_first: row.clear_arrears_first ? 1 : 0,
            $verification_status: row.verification_status,
            $rejection_reason: row.rejection_reason,
            $receipt_local_uri: row.receipt_local_uri,
            $receipt_server_path: row.receipt_server_path,
            $sync_status: row.sync_status,
            $sync_error: row.sync_error,
            $sync_attempts: row.sync_attempts,
            $created_at: row.created_at,
            $updated_at: row.updated_at,
        },
    );

    return row;
}

/** Batch push per offline-sync-strategy.md §8: up to 50 changes per request. */
const PUSH_BATCH_SIZE = 50;

export async function getQueuedPayments(): Promise<LocalPayment[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalPayment>(
        `SELECT * FROM payments WHERE sync_status IN ('queued', 'failed') ORDER BY created_at ASC LIMIT ?`,
        [PUSH_BATCH_SIZE],
    );
}

export async function setPaymentsSyncStatus(localUuids: string[], status: SyncStatus): Promise<void> {
    if (localUuids.length === 0) {
        return;
    }

    const db = await getDatabase();
    const placeholders = localUuids.map(() => '?').join(',');

    await db.runAsync(
        `UPDATE payments SET sync_status = ?, updated_at = ? WHERE local_uuid IN (${placeholders})`,
        [status, nowIso(), ...localUuids],
    );
}

export async function markPaymentSynced(localUuid: string, serverUuid: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE payments SET server_uuid = ?, sync_status = 'synced', sync_error = NULL, updated_at = ? WHERE local_uuid = ?`,
        [serverUuid, nowIso(), localUuid],
    );
}

export async function markPaymentFailed(localUuid: string, error: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE payments
         SET sync_status = 'failed', sync_error = ?, sync_attempts = sync_attempts + 1, updated_at = ?
         WHERE local_uuid = ?`,
        [error, nowIso(), localUuid],
    );
}

/**
 * Reconciles pull()'s changes.payments.verified/rejected into local rows,
 * matched by server_uuid — a queued item that hasn't completed its push
 * round-trip yet has no server_uuid and simply can't be touched by a pull,
 * per mobile-app-react-native.md §2's reconciliation note.
 */
export async function applyVerificationUpdates(
    payments: SyncPullChangedPayment[],
    status: 'verified' | 'rejected',
): Promise<void> {
    if (payments.length === 0) {
        return;
    }

    const db = await getDatabase();

    await db.withTransactionAsync(async () => {
        for (const payment of payments) {
            await db.runAsync(
                `UPDATE payments
                 SET verification_status = ?, rejection_reason = ?, updated_at = ?
                 WHERE server_uuid = ?`,
                [status, payment.rejection_reason ?? null, nowIso(), payment.uuid],
            );
        }
    });
}

export async function getTodayCollectionTotal(): Promise<number> {
    const db = await getDatabase();

    // Sums every payment recorded today by this device regardless of
    // sync_status (queued or synced — it's real cash already collected
    // either way, and must render instantly offline), excluding any that
    // came back rejected on a later pull.
    const row = await db.getFirstAsync<{ total: number | null }>(
        `SELECT SUM(amount) as total FROM payments
         WHERE created_at >= ? AND verification_status != 'rejected'`,
        [startOfTodayIso()],
    );

    return row?.total ?? 0;
}

export async function getQueuedPaymentsCount(): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ count: number }>(
        `SELECT COUNT(*) as count FROM payments WHERE sync_status IN ('queued', 'failed')`,
    );

    return row?.count ?? 0;
}

export async function getFailedPaymentsCount(): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ count: number }>(
        `SELECT COUNT(*) as count FROM payments WHERE sync_status = 'failed'`,
    );

    return row?.count ?? 0;
}

export async function getRecentPayments(limit = 50): Promise<LocalPayment[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalPayment>('SELECT * FROM payments ORDER BY created_at DESC LIMIT ?', [limit]);
}

/**
 * Most recent payment THIS DEVICE recorded for one customer, used by the
 * Customer Detail screen's "last payment date/amount". Note the local
 * `payments` table only ever holds payments this agent personally entered
 * (it is an outbox + this-device's-own-history view, not a full server-side
 * ledger — see mobile-app-react-native.md §2) — a customer with payment
 * history recorded by other agents/the web admin will show nothing here
 * even though the server has records; that fuller picture is what the live
 * GET /customers/{uuid} call's `recent_payments` is for. Returns the latest
 * row regardless of sync_status (not filtered to 'synced' only) so a
 * just-recorded, not-yet-synced payment is never hidden from the agent who
 * just entered it — screens should surface sync_status alongside it rather
 * than treating an unsynced row as absent.
 */
export async function getLastPaymentForCustomer(customerUuid: string): Promise<LocalPayment | null> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<LocalPayment>(
        'SELECT * FROM payments WHERE customer_uuid = ? ORDER BY created_at DESC LIMIT 1',
        [customerUuid],
    );

    return row ?? null;
}

/**
 * Looks up a single payment by its client-generated local_uuid — used by
 * the Record Payment confirmation state to poll for a near-instant
 * queued->synced transition (amber "Saved · will sync" -> green "Synced ✓")
 * without needing a per-item entry in the global sync store.
 */
export async function getPaymentByLocalUuid(localUuid: string): Promise<LocalPayment | null> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<LocalPayment>('SELECT * FROM payments WHERE local_uuid = ?', [localUuid]);

    return row ?? null;
}

/**
 * Payments that have synced (so a real server_uuid exists to address the
 * upload at) but whose captured receipt photo hasn't been pushed yet.
 * Picked up by SyncManager.uploadPendingReceipts() after each successful
 * push/pull cycle — per offline-sync-strategy.md §4.4, receipt upload is a
 * separate multipart request sent only once the payment itself has a
 * server_uuid, never bundled into the sync push payload.
 */
export async function getPaymentsAwaitingReceiptUpload(): Promise<LocalPayment[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalPayment>(
        `SELECT * FROM payments
         WHERE sync_status = 'synced' AND server_uuid IS NOT NULL
           AND receipt_local_uri IS NOT NULL AND receipt_server_path IS NULL`,
    );
}

export async function markPaymentReceiptUploaded(localUuid: string, receiptUrl: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(`UPDATE payments SET receipt_server_path = ?, updated_at = ? WHERE local_uuid = ?`, [
        receiptUrl,
        nowIso(),
        localUuid,
    ]);
}
