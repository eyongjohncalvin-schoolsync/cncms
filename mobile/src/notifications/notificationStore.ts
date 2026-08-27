import { getEmergenciesNeedingInterrupt, getUnacknowledgedEmergencies, getUnreadCount } from '../db/notifications';
import type { LocalNotification } from '../types/db';

/**
 * Minimal external store (subscribe/getSnapshot, useSyncExternalStore-
 * compatible) for the routine unread badge and the emergency
 * banner/interrupt — same dependency-free shape as src/sync/syncStore.ts,
 * refreshed by SyncManager after every successful pull() and readable by
 * any screen without re-querying SQLite itself. Kept as a separate store
 * from syncStore rather than folded in: this state is about notification
 * CONTENT (what's unread/unacknowledged), not sync mechanics (queued/
 * failed/offline) — conflating them would make syncStore's already
 * load-bearing four-state phase logic (mobile-app-react-native.md
 * section 5) harder to reason about.
 */
export interface NotificationsState {
    unreadCount: number;
    /** Every unacknowledged emergency, whether or not an acknowledge is
     * already queued — feeds the persistent banner. */
    unacknowledgedEmergencies: LocalNotification[];
    /** The subset that should still trigger the full-screen interrupt on
     * next app open (excludes ones already queued for acknowledge). */
    emergenciesNeedingInterrupt: LocalNotification[];
}

const initialState: NotificationsState = {
    unreadCount: 0,
    unacknowledgedEmergencies: [],
    emergenciesNeedingInterrupt: [],
};

let state: NotificationsState = initialState;
const listeners = new Set<() => void>();

export function getNotificationsState(): NotificationsState {
    return state;
}

export function subscribeNotificationsState(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function setState(next: NotificationsState): void {
    state = next;
    listeners.forEach((listener) => listener());
}

/** Re-reads the local cache and publishes a fresh snapshot — call after
 * every pull() and after any local ack-state mutation (markAckPending()/
 * markAckConfirmed()) so subscribers never show stale counts. */
export async function refreshNotificationsState(): Promise<void> {
    const [unreadCount, unacknowledgedEmergencies, emergenciesNeedingInterrupt] = await Promise.all([
        getUnreadCount(),
        getUnacknowledgedEmergencies(),
        getEmergenciesNeedingInterrupt(),
    ]);

    setState({ unreadCount, unacknowledgedEmergencies, emergenciesNeedingInterrupt });
}
