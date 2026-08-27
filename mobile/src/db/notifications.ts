import { getDatabase } from './database';
import type { LocalNotification } from '../types/db';
import type { SyncPullNotificationItem } from '../types/api';
import { nowIso } from '../utils/format';

/**
 * Local cache for in-app-notifications.md section 6 / complaint-desk.md
 * section 7's mobile delivery — read-only from the server's point of view
 * (replaced/merged from pull()'s `changes.notifications` block only, same
 * convention as src/db/customers.ts's upsertCustomers()), with exactly one
 * mobile-only local mutation: queuing an Acknowledge action while offline
 * (markAckPending()/markAckConfirmed() below).
 *
 * Deliberately NO local "mark read" write here — complaint-desk.md
 * section 7's "keep it proportionate" note means the mobile Notifications
 * section is display-only for the routine feed; read_at only ever reflects
 * state synced down from the server (e.g. the same notification already
 * read on web). Acknowledge is the one action that must be a real online
 * round trip (see markAckPending()'s doc comment) — read is not.
 */

/**
 * Merges the server's current notification state into the local cache.
 * Never regresses a locally-known `acknowledged_at` back to null, and
 * never clobbers a locally-queued `ack_pending` flag with the server's
 * still-null acknowledged_at while that queued action hasn't round-tripped
 * yet — both handled by the CASE expressions below rather than a plain
 * REPLACE, so a pull that lands mid-flight of a queued acknowledge can
 * never silently "undo" it from the UI's point of view.
 */
export async function upsertNotifications(items: SyncPullNotificationItem[]): Promise<void> {
    if (items.length === 0) {
        return;
    }

    const db = await getDatabase();
    const cachedAt = nowIso();

    await db.withTransactionAsync(async () => {
        for (const item of items) {
            await db.runAsync(
                `INSERT INTO notifications
                    (uuid, type, severity, title, body, link, source_type, source_uuid,
                     created_at, read_at, acknowledged_at, ack_pending, cached_at)
                 VALUES ($uuid, $type, $severity, $title, $body, $link, $source_type, $source_uuid,
                     $created_at, $read_at, $acknowledged_at, 0, $cached_at)
                 ON CONFLICT(uuid) DO UPDATE SET
                    type = excluded.type,
                    severity = excluded.severity,
                    title = excluded.title,
                    body = excluded.body,
                    link = excluded.link,
                    source_type = excluded.source_type,
                    source_uuid = excluded.source_uuid,
                    created_at = excluded.created_at,
                    read_at = excluded.read_at,
                    acknowledged_at = CASE
                        WHEN excluded.acknowledged_at IS NOT NULL THEN excluded.acknowledged_at
                        ELSE notifications.acknowledged_at
                    END,
                    ack_pending = CASE
                        WHEN excluded.acknowledged_at IS NOT NULL THEN 0
                        ELSE notifications.ack_pending
                    END,
                    cached_at = excluded.cached_at`,
                {
                    $uuid: item.uuid,
                    $type: item.type,
                    $severity: item.severity,
                    $title: item.title,
                    $body: item.body,
                    $link: item.link,
                    $source_type: item.source_type,
                    $source_uuid: item.source_uuid,
                    $created_at: item.created_at,
                    $read_at: item.read_at,
                    $acknowledged_at: item.acknowledged_at,
                    $cached_at: cachedAt,
                },
            );
        }
    });
}

export async function getRecentNotifications(limit = 20): Promise<LocalNotification[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalNotification>(`SELECT * FROM notifications ORDER BY created_at DESC LIMIT ?`, [limit]);
}

export async function getUnreadCount(): Promise<number> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ count: number }>(
        `SELECT COUNT(*) as count FROM notifications WHERE read_at IS NULL`,
    );

    return row?.count ?? 0;
}

/**
 * Every emergency notification still awaiting a confirmed acknowledge —
 * feeds the persistent red banner (complaint-desk.md section 7), which
 * stays visible whether the agent has never touched it (`ack_pending = 0`)
 * or has already pressed Acknowledge while offline and is waiting for
 * that to confirm (`ack_pending = 1`) — the banner's copy distinguishes
 * the two, but both count as "not yet acknowledged" for this query.
 */
export async function getUnacknowledgedEmergencies(): Promise<LocalNotification[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalNotification>(
        `SELECT * FROM notifications WHERE severity = 'emergency' AND acknowledged_at IS NULL ORDER BY created_at DESC`,
    );
}

/**
 * The narrower subset that should trigger the full-screen interrupt on
 * app open: never yet acted on at all (`ack_pending = 0` — a queued-but-
 * unconfirmed acknowledge already represents the agent having taken the
 * action, so it must NOT re-interrupt them again on next open, only the
 * banner should reflect it — complaint-desk.md section 7).
 */
export async function getEmergenciesNeedingInterrupt(): Promise<LocalNotification[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalNotification>(
        `SELECT * FROM notifications WHERE severity = 'emergency' AND acknowledged_at IS NULL AND ack_pending = 0 ORDER BY created_at DESC`,
    );
}

export async function getPendingAcknowledgements(): Promise<LocalNotification[]> {
    const db = await getDatabase();

    return db.getAllAsync<LocalNotification>(`SELECT * FROM notifications WHERE ack_pending = 1`);
}

/**
 * Called the instant the agent presses "Acknowledge" — see
 * app/emergency.tsx. This is deliberately NOT the same as marking it
 * acknowledged locally: `acknowledged_at` stays null until
 * SyncManager confirms the real POST /notifications/{uuid}/acknowledge
 * round-trip succeeded (markAckConfirmed() below). Setting `ack_pending`
 * here is what stops the full-screen interrupt from re-firing on the next
 * app open while still leaving the persistent banner visible until the
 * server actually confirms — complaint-desk.md section 7's "queue and
 * confirm once connectivity returns, don't silently drop it."
 */
export async function markAckPending(uuid: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(`UPDATE notifications SET ack_pending = 1 WHERE uuid = ?`, [uuid]);
}

export async function markAckConfirmed(uuid: string, acknowledgedAt: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `UPDATE notifications SET acknowledged_at = ?, ack_pending = 0 WHERE uuid = ?`,
        [acknowledgedAt, uuid],
    );
}
