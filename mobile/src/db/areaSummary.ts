import { getMeta, setMeta } from './syncMeta';
import type { AreaSummary } from '../types/api';

/**
 * The server's area figures from the last pull (App\Services\
 * AreaSummaryService — the same numbers the web dashboard shows), kept so
 * Home works offline "as of the last sync". Cleared with the rest of
 * sync_meta when another account signs in (src/db/localData.ts).
 */
const KEY = 'area_summary';

export interface StoredAreaSummary extends AreaSummary {
    as_of: string;
}

export async function getAreaSummary(): Promise<StoredAreaSummary | null> {
    const raw = await getMeta(KEY);

    if (raw === null) {
        return null;
    }

    try {
        return JSON.parse(raw) as StoredAreaSummary;
    } catch {
        return null;
    }
}

export async function saveAreaSummary(summary: AreaSummary, asOf: string): Promise<void> {
    await setMeta(KEY, JSON.stringify({ ...summary, as_of: asOf }));
}
