import { getDatabase } from './database';
import { nowIso } from '../utils/format';
import type { LocalInstallation } from '../types/db';
import type { SyncPullInstallationDecision } from '../types/api';
import type { FieldInstallationItem } from '../utils/fieldInstallation';

/**
 * Customers registered in the field, offline (installation phase 3) —
 * outbox and history in one table, the same convention as complaints. A
 * queued registration also lands in the local customers cache straight
 * away (marked pending activation) so the agent finds the customer at
 * once; the pull replaces it with the server's row.
 */

const PUSH_BATCH_SIZE = 50;

export async function insertLocalInstallation(item: FieldInstallationItem, collectNow: number, bill: number): Promise<void> {
    const db = await getDatabase();
    const now = nowIso();

    await db.withTransactionAsync(async () => {
        await db.runAsync(
            `INSERT INTO installations (local_uuid, customer_uuid, name, phone, collect_now, payload, sync_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'queued', ?, ?)`,
            [item.local_uuid, item.customer_uuid, item.name, item.phone, collectNow, JSON.stringify(item), now, now],
        );

        await db.runAsync(
            `INSERT INTO customers (uuid, name, phone, bill, location, level, status, zone_uuid, cached_at, total_arrears, credit, services, promo)
             VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, NULL, NULL, ?, ?)
             ON CONFLICT(uuid) DO NOTHING`,
            [
                item.customer_uuid,
                item.name,
                item.phone,
                bill,
                item.location,
                item.level,
                item.zone_uuid,
                now,
                JSON.stringify(item.services.map((row) => ({ ...row, service_name: '', service_variant_name: null }))),
                JSON.stringify({ activation_status: 'pending' }),
            ],
        );
    });
}

export async function getQueuedInstallations(): Promise<LocalInstallation[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalInstallation>(
        `SELECT * FROM installations WHERE sync_status IN ('queued', 'failed') ORDER BY created_at ASC LIMIT ?`,
        [PUSH_BATCH_SIZE],
    );
}

export async function markInstallationSynced(localUuid: string, activation: string | undefined, possibleDuplicate: boolean | undefined): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE installations
         SET sync_status = 'synced', sync_error = NULL, activation = COALESCE(?, activation, 'pending'),
             possible_duplicate = ?, updated_at = ?
         WHERE local_uuid = ?`,
        [activation ?? null, possibleDuplicate ? 1 : 0, nowIso(), localUuid],
    );
}

export async function markInstallationFailed(localUuid: string, error: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE installations
         SET sync_status = 'failed', sync_error = ?, sync_attempts = sync_attempts + 1, updated_at = ?
         WHERE local_uuid = ?`,
        [error, nowIso(), localUuid],
    );
}

/** The office's decisions from the pull: Pending activation / Activated / Rejected: reason. */
export async function applyInstallationDecisions(decisions: SyncPullInstallationDecision[]): Promise<void> {
    if (decisions.length === 0) {
        return;
    }

    const db = await getDatabase();
    const now = nowIso();

    await db.withTransactionAsync(async () => {
        for (const decision of decisions) {
            await db.runAsync(
                `UPDATE installations
                 SET activation = ?, decision_reason = ?, possible_duplicate = ?, sync_status = 'synced', sync_error = NULL, updated_at = ?
                 WHERE local_uuid = ?`,
                [decision.status, decision.reason, decision.possible_duplicate ? 1 : 0, now, decision.local_uuid],
            );
        }
    });
}

export async function getQueuedInstallationsCount(): Promise<number> {
    const db = await getDatabase();
    const row = await db.getFirstAsync<{ count: number }>(`SELECT COUNT(*) as count FROM installations WHERE sync_status IN ('queued', 'failed')`);

    return row?.count ?? 0;
}

export async function getFailedInstallationsCount(): Promise<number> {
    const db = await getDatabase();
    const row = await db.getFirstAsync<{ count: number }>(`SELECT COUNT(*) as count FROM installations WHERE sync_status = 'failed'`);

    return row?.count ?? 0;
}

/** Newest first, for the sync status and history screens. */
export async function getRecentInstallations(limit = 50): Promise<LocalInstallation[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalInstallation>('SELECT * FROM installations ORDER BY created_at DESC LIMIT ?', [limit]);
}
