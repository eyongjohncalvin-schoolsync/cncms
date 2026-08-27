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
