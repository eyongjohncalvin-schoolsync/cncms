import { getDatabase } from './database';
import { generateUuid } from '../utils/uuid';
import type { SyncMetaKey } from '../types/db';

export async function getMeta(key: SyncMetaKey): Promise<string | null> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ value: string }>('SELECT value FROM sync_meta WHERE key = ?', [key]);

    return row?.value ?? null;
}

export async function setMeta(key: SyncMetaKey, value: string): Promise<void> {
    const db = await getDatabase();

    await db.runAsync(
        `INSERT INTO sync_meta (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
        [key, value],
    );
}

/** Generated once on first launch and persisted — sent as sync_queue's
 * `device_id` and stored server-side on agents.sync_token. */
export async function getOrCreateDeviceId(): Promise<string> {
    const existing = await getMeta('device_id');

    if (existing) {
        return existing;
    }

    const deviceId = generateUuid();
    await setMeta('device_id', deviceId);

    return deviceId;
}

export async function getLastSyncAt(): Promise<string | null> {
    return getMeta('last_sync_at');
}

export async function setLastSyncAt(iso: string): Promise<void> {
    await setMeta('last_sync_at', iso);
}

/**
 * Resets the delta-sync watermark so the next pull() is treated as a full
 * first-login sync (SyncService::pull()'s `$sinceAt` becomes null, so every
 * eligible customer is returned regardless of `customers.updated_at`) rather
 * than only customers changed since last_sync_at.
 *
 * Exists because `upsertedCustomers()`'s `updated_at >= $since` filter is
 * blind to any change that doesn't also touch the customer row itself —
 * manuscript recalculation/deletion never does (no Eloquent `$touches`
 * relationship exists from Manuscript back to Customer), so a customer's
 * cached `total_arrears`/`credit` can go stale on-device indefinitely with
 * no normal sync trigger ever correcting it, most visibly after a direct
 * database intervention (which additionally bypasses every app-level write
 * path, including any `updated_at` an ordinary write would produce) but not
 * exclusively caused by one. See SyncManager.forceFullResync() and
 * mobile-app-react-native.md's dated addendum on this.
 */
export async function clearLastSyncAt(): Promise<void> {
    const db = await getDatabase();

    await db.runAsync('DELETE FROM sync_meta WHERE key = ?', ['last_sync_at']);
}
