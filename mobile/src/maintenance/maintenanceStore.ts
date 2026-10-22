import type { MaintenanceWindowInfo } from '../utils/maintenanceState';

/**
 * Minimal external store (useSyncExternalStore-compatible, same shape as
 * src/notifications/notificationStore.ts) for the platform maintenance
 * window. Fed by the response interceptor in src/api/client.ts; in memory
 * only — the next API response re-announces it.
 */
let state: MaintenanceWindowInfo | null = null;
const listeners = new Set<() => void>();

export function getMaintenanceState(): MaintenanceWindowInfo | null {
    return state;
}

export function subscribeMaintenanceState(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function setMaintenanceState(next: MaintenanceWindowInfo | null): void {
    if (next?.startsAt === state?.startsAt && next?.endsAt === state?.endsAt) {
        return;
    }

    state = next;
    listeners.forEach((listener) => listener());
}
