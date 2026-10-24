import { fetchApprovals } from '../api/expenditures';
import { getSyncState } from './syncStore';

/**
 * How many expenses are waiting for THIS user's approval — a tiny external
 * store (same subscribe/getSnapshot shape as syncStore.ts, usable with
 * useSyncExternalStore) so the Home card, the More tab badge and the
 * Approvals inbox all agree, and approving one updates the badges at once.
 * Online-only: `count` stays null until a fetch has succeeded, and is kept
 * (not reset) when offline so a badge doesn't flicker away.
 */
export interface ApprovalsState {
    count: number | null;
}

let state: ApprovalsState = { count: null };
const listeners = new Set<() => void>();
let inFlight: Promise<void> | null = null;

export function getApprovalsState(): ApprovalsState {
    return state;
}

export function subscribeApprovalsState(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function setApprovalsCount(count: number | null): void {
    if (state.count === count) {
        return;
    }

    state = { count };
    listeners.forEach((listener) => listener());
}

/** Best-effort refresh; a no-op offline. Concurrent callers share one request. */
export function refreshApprovalsCount(): Promise<void> {
    if (!getSyncState().isOnline) {
        return Promise.resolve();
    }

    if (!inFlight) {
        inFlight = fetchApprovals()
            .then((response) => setApprovalsCount(response.data.length))
            .catch(() => {
                // Keep the last known count — offline or a transient error.
            })
            .finally(() => {
                inFlight = null;
            });
    }

    return inFlight;
}
