import type { AppUpdateInfo } from '../utils/appUpdate';

/**
 * The latest APK the server announces (src/utils/appUpdate.ts) — the same
 * minimal useSyncExternalStore-compatible store as maintenanceStore.ts. Fed
 * by the response interceptor in src/api/client.ts; in memory only.
 */
let state: AppUpdateInfo | null = null;
const listeners = new Set<() => void>();

export function getAppUpdateState(): AppUpdateInfo | null {
    return state;
}

export function subscribeAppUpdateState(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function setAppUpdateState(next: AppUpdateInfo | null): void {
    if (next?.latestVersion === state?.latestVersion && next?.downloadUrl === state?.downloadUrl) {
        return;
    }

    state = next;
    listeners.forEach((listener) => listener());
}
