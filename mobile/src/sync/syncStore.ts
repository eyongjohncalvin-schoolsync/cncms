/**
 * Minimal external store (subscribe/getSnapshot, compatible with
 * useSyncExternalStore) for the persistent sync-status strip and any other
 * screen that needs to react to sync state. Kept dependency-free rather
 * than pulling in a state-management library for four fields.
 */

export type SyncPhase = 'offline' | 'syncing' | 'synced' | 'error';

export interface SyncState {
    phase: SyncPhase;
    isOnline: boolean;
    /** Items with sync_status IN ('queued','failed') — not yet confirmed on server. */
    queuedCount: number;
    /** Items with sync_status = 'failed' specifically — a real error occurred while online. */
    failedCount: number;
    syncingProgress: { done: number; total: number } | null;
    lastSyncAt: string | null;
    lastError: string | null;
}

const initialState: SyncState = {
    phase: 'synced',
    isOnline: true,
    queuedCount: 0,
    failedCount: 0,
    syncingProgress: null,
    lastSyncAt: null,
    lastError: null,
};

let state: SyncState = initialState;
const listeners = new Set<() => void>();

export function getSyncState(): SyncState {
    return state;
}

export function subscribeSyncState(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

/**
 * Derives the four-state phase from raw counters. `offline` covers both
 * "genuinely no connection" and "online but hasn't attempted this queue
 * yet" — both render with the same calm amber treatment because from the
 * agent's point of view they mean the same thing: "saved, not synced yet,
 * nothing to worry about." Only a failed item while online counts as a
 * real, attention-worthy error. See mobile-app-react-native.md §5.
 */
function computePhase(next: Omit<SyncState, 'phase'>): SyncPhase {
    if (next.syncingProgress !== null) {
        return 'syncing';
    }

    if (next.failedCount > 0 && next.isOnline) {
        return 'error';
    }

    if (next.queuedCount > 0) {
        return 'offline';
    }

    return 'synced';
}

export function patchSyncState(patch: Partial<Omit<SyncState, 'phase'>>): void {
    const next = { ...state, ...patch };

    state = { ...next, phase: computePhase(next) };

    listeners.forEach((listener) => listener());
}
