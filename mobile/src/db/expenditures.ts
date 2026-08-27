import { getDatabase } from './database';
import type { LocalExpenditure, SyncStatus } from '../types/db';
import { generateUuid } from '../utils/uuid';
import { nowIso } from '../utils/format';

export interface NewLocalExpenditureInput {
    category_uuid: string;
    amount: number;
    description?: string | null;
    spent_at: string;
    notes?: string | null;
    receipt_local_uri?: string | null;
}

export async function insertLocalExpenditure(input: NewLocalExpenditureInput): Promise<LocalExpenditure> {
    const db = await getDatabase();
    const now = nowIso();

    const row: LocalExpenditure = {
        local_uuid: generateUuid(),
        server_uuid: null,
        category_uuid: input.category_uuid,
        amount: input.amount,
        description: input.description ?? null,
        spent_at: input.spent_at,
        notes: input.notes ?? null,
        receipt_local_uri: input.receipt_local_uri ?? null,
        receipt_server_path: null,
        sync_status: 'queued',
        sync_error: null,
        sync_attempts: 0,
        created_at: now,
        updated_at: now,
    };

    await db.runAsync(
        `INSERT INTO expenditures
            (local_uuid, server_uuid, category_uuid, amount, description, spent_at, notes,
             receipt_local_uri, receipt_server_path, sync_status, sync_error, sync_attempts,
             created_at, updated_at)
         VALUES ($local_uuid, $server_uuid, $category_uuid, $amount, $description, $spent_at, $notes,
             $receipt_local_uri, $receipt_server_path, $sync_status, $sync_error, $sync_attempts,
             $created_at, $updated_at)`,
        {
            $local_uuid: row.local_uuid,
            $server_uuid: row.server_uuid,
            $category_uuid: row.category_uuid,
            $amount: row.amount,
            $description: row.description,
            $spent_at: row.spent_at,
            $notes: row.notes,
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
