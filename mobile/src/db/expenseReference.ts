import { getMeta, setMeta } from './syncMeta';
import type { ExpenditureApi, ExpenseLimitsApi } from '../types/api';

/**
 * Small JSON blobs in `sync_meta` that let the expense screens work offline:
 * the company's expense limits (Record Expense's live hints), the service
 * list (its "Service" picker) and the last known server status of this
 * user's own expenses (History's status badges). Each read tolerates a
 * missing or corrupt value by returning the empty case — a stale/missing
 * cache only hides hints, it never blocks recording an expense.
 */

const MAX_CACHED_STATUSES = 200;

export interface CachedService {
    uuid: string;
    name: string;
}

async function readJson<T>(key: Parameters<typeof getMeta>[0]): Promise<T | null> {
    const raw = await getMeta(key);

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw) as T;
    } catch {
        return null;
    }
}

export async function getCachedExpenseLimits(): Promise<ExpenseLimitsApi | null> {
    return readJson<ExpenseLimitsApi>('expense_limits');
}

export async function setCachedExpenseLimits(limits: ExpenseLimitsApi): Promise<void> {
    await setMeta('expense_limits', JSON.stringify(limits));
}

export async function getCachedServices(): Promise<CachedService[]> {
    return (await readJson<CachedService[]>('expense_services')) ?? [];
}

export async function setCachedServices(services: CachedService[]): Promise<void> {
    await setMeta('expense_services', JSON.stringify(services));
}

/** Last known server-side view of this user's expenses, keyed by server uuid. */
export async function getCachedExpenseStatuses(): Promise<Record<string, ExpenditureApi>> {
    return (await readJson<Record<string, ExpenditureApi>>('expense_server_status')) ?? {};
}

/** Merges fresh server rows into the cache (a single updated row from the
 * detail screen must not wipe the rest of the list). */
export async function mergeCachedExpenseStatuses(rows: ExpenditureApi[]): Promise<Record<string, ExpenditureApi>> {
    const current = await getCachedExpenseStatuses();

    for (const row of rows) {
        current[row.uuid] = row;
    }

    // Bounded: only the newest rows matter for the History list.
    const kept = Object.values(current)
        .sort((a, b) => ((a.created_at ?? '') < (b.created_at ?? '') ? 1 : -1))
        .slice(0, MAX_CACHED_STATUSES);
    const trimmed = Object.fromEntries(kept.map((row) => [row.uuid, row]));

    await setMeta('expense_server_status', JSON.stringify(trimmed));

    return trimmed;
}

export async function getExpenseReferenceRefreshedAt(): Promise<string | null> {
    return getMeta('expense_reference_refreshed_at');
}

export async function setExpenseReferenceRefreshedAt(iso: string): Promise<void> {
    await setMeta('expense_reference_refreshed_at', iso);
}
