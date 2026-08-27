import { getDatabase } from './database';
import type { LocalComplaint, SyncStatus } from '../types/db';
import { buildLocalComplaintRow, type NewLocalComplaintInput } from '../utils/complaintOutbox';
import { generateUuid } from '../utils/uuid';
import { nowIso } from '../utils/format';

export type { NewLocalComplaintInput } from '../utils/complaintOutbox';

/**
 * "Log a Complaint" local outbox writes — mirrors src/db/expenditures.ts's
 * insertLocalExpenditure() exactly (complaint-desk.md section 7). Writes
 * to SQLite immediately with sync_status='queued'; SyncManager's push()
 * cycle picks it up from here, same offline-first pattern as every other
 * mobile submission screen. Row shape itself is built by the pure
 * buildLocalComplaintRow() (src/utils/complaintOutbox.ts) — see that
 * module's doc comment for why the split exists.
 */
export async function insertLocalComplaint(input: NewLocalComplaintInput): Promise<LocalComplaint> {
    const db = await getDatabase();
    const row = buildLocalComplaintRow(input, generateUuid(), nowIso());

    await db.runAsync(
        `INSERT INTO complaints
            (local_uuid, server_uuid, category, title, description, urgent, customer_uuid,
             sync_status, sync_error, sync_attempts, created_at, updated_at)
         VALUES ($local_uuid, $server_uuid, $category, $title, $description, $urgent, $customer_uuid,
             $sync_status, $sync_error, $sync_attempts, $created_at, $updated_at)`,
        {
            $local_uuid: row.local_uuid,
            $server_uuid: row.server_uuid,
            $category: row.category,
            $title: row.title,
            $description: row.description,
            $urgent: row.urgent,
            $customer_uuid: row.customer_uuid,
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

export async function getQueuedComplaints(): Promise<LocalComplaint[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalComplaint>(
        `SELECT * FROM complaints WHERE sync_status IN ('queued', 'failed') ORDER BY created_at ASC LIMIT ?`,
        [PUSH_BATCH_SIZE],
    );
}

export async function markComplaintSynced(localUuid: string, serverUuid: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE complaints SET server_uuid = ?, sync_status = 'synced', sync_error = NULL, updated_at = ? WHERE local_uuid = ?`,
        [serverUuid, nowIso(), localUuid],
    );
}

export async function markComplaintFailed(localUuid: string, error: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE complaints
         SET sync_status = 'failed', sync_error = ?, sync_attempts = sync_attempts + 1, updated_at = ?
         WHERE local_uuid = ?`,
        [error, nowIso(), localUuid],
    );
}

export async function getQueuedComplaintsCount(): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ count: number }>(
        `SELECT COUNT(*) as count FROM complaints WHERE sync_status IN ('queued', 'failed')`,
    );

    return row?.count ?? 0;
}

export async function getFailedComplaintsCount(): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ count: number }>(
        `SELECT COUNT(*) as count FROM complaints WHERE sync_status = 'failed'`,
    );

    return row?.count ?? 0;
}

/**
 * The full local outbox+history table, newest first — for the read-only
 * "Complaints" list screen (app/complaints.tsx), mirroring
 * getRecentPayments()'s exact shape in src/db/payments.ts. Every row here
 * originated on THIS device by construction (complaint sync is push/
 * create-only — there is no pull-back of complaints from the server), so
 * there is no separate "mine" filter to apply.
 */
export async function getRecentComplaints(limit = 100): Promise<LocalComplaint[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalComplaint>('SELECT * FROM complaints ORDER BY created_at DESC LIMIT ?', [limit]);
}
