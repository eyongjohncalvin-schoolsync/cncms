import { fetchInbox, type InboxItem } from '../api/inbox';
import { extractErrorMessage, isNetworkError } from '../api/client';

/**
 * The manager inbox's state (docs/plans/mobile-manager-app.md, wave 1),
 * shared by the Inbox tab's badge and screen — the same dependency-free
 * subscribe/getSnapshot shape as src/notifications/notificationStore.ts.
 * Kept in memory only: the inbox is about decisions, which need a
 * connection; offline, the last list stays on screen marked as such.
 */
export interface InboxState {
    items: InboxItem[];
    nowCount: number;
    loading: boolean;
    /** True when the last refresh failed for lack of a connection. */
    offline: boolean;
    error: string | null;
    loadedAt: number | null;
}

let state: InboxState = { items: [], nowCount: 0, loading: false, offline: false, error: null, loadedAt: null };
const listeners = new Set<() => void>();

export function getInboxState(): InboxState {
    return state;
}

export function subscribeInboxState(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function setState(patch: Partial<InboxState>): void {
    state = { ...state, ...patch };
    listeners.forEach((listener) => listener());
}

export async function refreshInbox(): Promise<void> {
    if (state.loading) {
        return;
    }

    setState({ loading: true });

    try {
        const response = await fetchInbox();
        setState({ items: response.data, nowCount: response.counts.now, loading: false, offline: false, error: null, loadedAt: Date.now() });
    } catch (error) {
        setState({
            loading: false,
            offline: isNetworkError(error),
            error: isNetworkError(error) ? null : extractErrorMessage(error, 'Could not load your inbox.'),
        });
    }
}

/** Drops an item at once after a decision, before the next refresh confirms it. */
export function removeInboxItem(key: string): void {
    const items = state.items.filter((item) => item.key !== key);
    setState({ items, nowCount: items.filter((item) => item.severity === 'now').length });
}

/** On sign-out: nothing of the previous user may show. */
export function clearInbox(): void {
    setState({ items: [], nowCount: 0, loading: false, offline: false, error: null, loadedAt: null });
}
