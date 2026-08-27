import { getDatabase } from './database';

/**
 * Derives the current agent's own zone from the already-synced `customers`
 * cache, rather than a dedicated local zones table (none exists — see
 * mobile-app-react-native.md's zones-screen brief; `sync_meta`'s
 * `agent_zone_uuid` key is declared in src/types/db.ts but nothing in this
 * codebase actually writes it yet, so it isn't a reliable source today).
 *
 * This is sound because App\Services\SyncService::pull() already scopes an
 * `agent` role's customers to their own zone server-side
 * (App\Support\TenantContext::zoneId — see that class's doc comment on how
 * an agent's zone fence is resolved), so every row in the local `customers`
 * cache shares the same `zone_uuid` for a given agent device — picking any
 * one non-null value answers "what zone is this device's agent in" exactly
 * as well as a dedicated column would, with no new table needed.
 *
 * Returns `null` before the first sync has ever landed a customer (a
 * brand-new device, or a zone with zero customers) or for a non-`agent`
 * role (whose cached customers, if any, aren't zone-scoped the same way) —
 * callers should treat `null` as "not yet known," not an error.
 */
export async function getMyZoneUuid(): Promise<string | null> {
    const db = await getDatabase();

    const row = await db.getFirstAsync<{ zone_uuid: string | null }>(
        'SELECT zone_uuid FROM customers WHERE zone_uuid IS NOT NULL LIMIT 1',
    );

    return row?.zone_uuid ?? null;
}
