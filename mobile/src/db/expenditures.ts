import { getDatabase } from './database';
import type { LocalExpenditure, PaymentMethod, ReceiptSource, ReceiptUploadState, SyncStatus } from '../types/db';
import type { CategoryUsage } from '../utils/expenseControls';
import { generateUuid } from '../utils/uuid';
import { nowIso } from '../utils/format';

export interface NewLocalExpenditureInput {
    category_uuid: string;
    amount: number;
    description?: string | null;
    spent_at: string;
    notes?: string | null;
    payment_method?: PaymentMethod | null;
    payment_ref?: string | null;
    supplier_uuid?: string | null;
    service_uuid?: string | null;
    reference_no?: string | null;
    receipt_local_uri?: string | null;
    receipt_source?: ReceiptSource | null;
    receipt_captured_at?: string | null;
}

function blankToNull(value: string | null | undefined): string | null {
    const trimmed = value?.trim() ?? '';

    return trimmed === '' ? null : trimmed;
}

export async function insertLocalExpenditure(input: NewLocalExpenditureInput): Promise<LocalExpenditure> {
    const db = await getDatabase();
    const now = nowIso();
    const receiptUri = input.receipt_local_uri ?? null;

    const row: LocalExpenditure = {
        local_uuid: generateUuid(),
        server_uuid: null,
        category_uuid: input.category_uuid,
        amount: input.amount,
        description: blankToNull(input.description),
        spent_at: input.spent_at,
        notes: blankToNull(input.notes),
        payment_method: input.payment_method ?? null,
        payment_ref: blankToNull(input.payment_ref),
        supplier_uuid: input.supplier_uuid ?? null,
        service_uuid: input.service_uuid ?? null,
        reference_no: blankToNull(input.reference_no),
        receipt_local_uri: receiptUri,
        receipt_server_path: null,
        receipt_source: receiptUri ? (input.receipt_source ?? 'camera') : null,
        receipt_captured_at: receiptUri ? (input.receipt_captured_at ?? now) : null,
        receipt_uploaded: 0,
        sync_status: 'queued',
        sync_error: null,
        sync_attempts: 0,
        created_at: now,
        updated_at: now,
    };

    await db.runAsync(
        `INSERT INTO expenditures
            (local_uuid, server_uuid, category_uuid, amount, description, spent_at, notes,
             payment_method, payment_ref, supplier_uuid, service_uuid, reference_no,
             receipt_local_uri, receipt_server_path, receipt_source, receipt_captured_at, receipt_uploaded,
             sync_status, sync_error, sync_attempts, created_at, updated_at)
         VALUES ($local_uuid, $server_uuid, $category_uuid, $amount, $description, $spent_at, $notes,
             $payment_method, $payment_ref, $supplier_uuid, $service_uuid, $reference_no,
             $receipt_local_uri, $receipt_server_path, $receipt_source, $receipt_captured_at, $receipt_uploaded,
             $sync_status, $sync_error, $sync_attempts, $created_at, $updated_at)`,
        {
            $local_uuid: row.local_uuid,
            $server_uuid: row.server_uuid,
            $category_uuid: row.category_uuid,
            $amount: row.amount,
            $description: row.description,
            $spent_at: row.spent_at,
            $notes: row.notes,
            $payment_method: row.payment_method,
            $payment_ref: row.payment_ref,
            $supplier_uuid: row.supplier_uuid,
            $service_uuid: row.service_uuid,
            $reference_no: row.reference_no,
            $receipt_local_uri: row.receipt_local_uri,
            $receipt_server_path: row.receipt_server_path,
            $receipt_source: row.receipt_source,
            $receipt_captured_at: row.receipt_captured_at,
            $receipt_uploaded: row.receipt_uploaded,
            $sync_status: row.sync_status,
            $sync_error: row.sync_error,
            $sync_attempts: row.sync_attempts,
            $created_at: row.created_at,
            $updated_at: row.updated_at,
        },
    );

    return row;
}

const PUSH_BATCH_SIZE = 50;

export async function getQueuedExpenditures(): Promise<LocalExpenditure[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalExpenditure>(
        `SELECT * FROM expenditures WHERE sync_status IN ('queued', 'failed') ORDER BY created_at ASC LIMIT ?`,
        [PUSH_BATCH_SIZE],
    );
}

export async function setExpendituresSyncStatus(localUuids: string[], status: SyncStatus): Promise<void> {
    if (localUuids.length === 0) {
        return;
    }

    const db = await getDatabase();
    const placeholders = localUuids.map(() => '?').join(',');

    await db.runAsync(
        `UPDATE expenditures SET sync_status = ?, updated_at = ? WHERE local_uuid IN (${placeholders})`,
        [status, nowIso(), ...localUuids],
    );
}

export async function markExpenditureSynced(localUuid: string, serverUuid: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE expenditures SET server_uuid = ?, sync_status = 'synced', sync_error = NULL, updated_at = ? WHERE local_uuid = ?`,
        [serverUuid, nowIso(), localUuid],
    );
}

export async function markExpenditureFailed(localUuid: string, error: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE expenditures
         SET sync_status = 'failed', sync_error = ?, sync_attempts = sync_attempts + 1, updated_at = ?
         WHERE local_uuid = ?`,
        [error, nowIso(), localUuid],
    );
}

export async function getQueuedExpendituresCount(): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ count: number }>(
        `SELECT COUNT(*) as count FROM expenditures WHERE sync_status IN ('queued', 'failed')`,
    );

    return row?.count ?? 0;
}

export async function getFailedExpendituresCount(): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ count: number }>(
        `SELECT COUNT(*) as count FROM expenditures WHERE sync_status = 'failed'`,
    );

    return row?.count ?? 0;
}

/**
 * This device's own recorded expenditures, newest first — the Resources
 * screen's "my recorded expenditures" history, mirroring
 * src/db/payments.ts's getRecentPayments() for the same shape of screen.
 */
export async function getRecentExpenditures(limit = 100): Promise<LocalExpenditure[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalExpenditure>('SELECT * FROM expenditures ORDER BY created_at DESC LIMIT ?', [limit]);
}

/**
 * Sum of this device's own recorded expenditures with `spent_at >=
 * startDateOnly` (inclusive, `YYYY-MM-DD`) — backs the Resources screen's
 * today/week/month period total. Deliberately a SQL aggregate over the full
 * local table rather than a client-side sum over getRecentExpenditures()'s
 * LIMIT-capped list, so the total stays correct even once an agent has
 * recorded more rows than that list's limit — mirrors payments.ts's
 * getTodayCollectionTotal(). Includes every sync_status (queued or synced):
 * it's real money already spent either way, and expenditures carry no
 * verification workflow to exclude by (see
 * app/Policies/ExpenditurePolicy.php's own doc comment).
 */
export async function getExpenditureTotalSince(startDateOnly: string): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ total: number | null }>(
        `SELECT SUM(amount) as total FROM expenditures WHERE spent_at >= ?`,
        [startDateOnly],
    );

    return row?.total ?? 0;
}

export async function getExpenditureByLocalUuid(localUuid: string): Promise<LocalExpenditure | null> {
    const db = await getDatabase();

    return db.getFirstAsync<LocalExpenditure>('SELECT * FROM expenditures WHERE local_uuid = ?', [localUuid]);
}

/** The most recently recorded expense — backs Record Expense's "Repeat
 * last" chip (same category, amount, payer, supplier and service). */
export async function getLastExpenditure(): Promise<LocalExpenditure | null> {
    const db = await getDatabase();

    return db.getFirstAsync<LocalExpenditure>('SELECT * FROM expenditures ORDER BY created_at DESC LIMIT 1');
}

/**
 * How often (and how recently) this device has used each category over the
 * last 90 days — Record Expense ranks its category tiles with this via
 * rankCategoriesByUsage() so the usual ones sit first.
 */
export async function getCategoryUsage(sinceIso?: string): Promise<CategoryUsage[]> {
    const db = await getDatabase();
    const since = sinceIso ?? new Date(Date.now() - 90 * 86_400_000).toISOString();

    return db.getAllAsync<CategoryUsage>(
        `SELECT category_uuid, COUNT(*) as use_count, MAX(created_at) as last_used_at
         FROM expenditures WHERE created_at >= ? GROUP BY category_uuid`,
        [since],
    );
}

/**
 * Expenses that have synced (a real server_uuid exists to address the
 * upload at) whose captured receipt photo hasn't reached the server yet.
 * SyncManager.uploadPendingReceipts() sends these after each push/pull —
 * the receipt is always a separate multipart request, never part of push.
 */
export async function getExpendituresAwaitingReceiptUpload(): Promise<LocalExpenditure[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalExpenditure>(
        `SELECT * FROM expenditures
         WHERE sync_status = 'synced' AND server_uuid IS NOT NULL
           AND receipt_local_uri IS NOT NULL AND receipt_uploaded = 0
         ORDER BY created_at ASC`,
    );
}

/** Marks the photo as delivered (1) or permanently refused by the server (2). */
export async function markExpenditureReceiptUploaded(
    localUuid: string,
    state: Exclude<ReceiptUploadState, 0> = 1,
    serverPath: string | null = null,
): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE expenditures SET receipt_uploaded = ?, receipt_server_path = COALESCE(?, receipt_server_path), updated_at = ?
         WHERE local_uuid = ?`,
        [state, serverPath, nowIso(), localUuid],
    );
}

/**
 * Attaches a receipt photo to an already-recorded expense ("Add receipt" on
 * the expense detail screen). `uploaded` true when the screen already sent
 * it straight to the server; false queues it for SyncManager.
 */
export async function setExpenditureReceipt(
    localUuid: string,
    receipt: { uri: string; source: ReceiptSource; capturedAt: string },
    uploaded: boolean,
): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE expenditures
         SET receipt_local_uri = ?, receipt_source = ?, receipt_captured_at = ?, receipt_uploaded = ?, updated_at = ?
         WHERE local_uuid = ?`,
        [receipt.uri, receipt.source, receipt.capturedAt, uploaded ? 1 : 0, nowIso(), localUuid],
    );
}
