/**
 * Pure decision logic for the platform maintenance banner (server side:
 * App\Support\MaintenanceMode). The server announces a window two ways:
 * X-Maintenance-Starts-At / X-Maintenance-Ends-At headers on every tenant
 * API response (advance notice), and a 503 with code MAINTENANCE while the
 * window is running. Kept dependency-free so it is unit-testable.
 */

export interface MaintenanceWindowInfo {
    startsAt: string;
    endsAt: string;
}

export interface MaintenanceBannerView {
    visible: boolean;
    active: boolean;
    label: string | null;
}

/** Endpoints outside the server's tenant group never carry the headers, so
 * their absence there must not clear a known window. */
const UNANNOUNCED_PATHS = /(^|\/)auth\/(login|logout|profile|password)$/;

export function isAnnouncingEndpoint(url: string | undefined): boolean {
    return !!url && !UNANNOUNCED_PATHS.test(url.split('?')[0]);
}

/** "2h 05m", "12m", or "under a minute". */
export function formatRemaining(ms: number): string {
    const totalMinutes = Math.floor(ms / 60000);

    if (totalMinutes < 1) {
        return 'under a minute';
    }

    const days = Math.floor(totalMinutes / 1440);
    const hours = Math.floor((totalMinutes % 1440) / 60);
    const minutes = totalMinutes % 60;

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    return hours > 0 ? `${hours}h ${String(minutes).padStart(2, '0')}m` : `${minutes}m`;
}

function clock(iso: string): string {
    const date = new Date(iso);

    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

export function describeMaintenanceBanner(window: MaintenanceWindowInfo | null, nowMs: number): MaintenanceBannerView {
    if (!window) {
        return { visible: false, active: false, label: null };
    }

    const startsMs = new Date(window.startsAt).getTime();
    const endsMs = new Date(window.endsAt).getTime();

    if (Number.isNaN(startsMs) || Number.isNaN(endsMs) || nowMs >= endsMs) {
        return { visible: false, active: false, label: null };
    }

    if (nowMs >= startsMs) {
        return {
            visible: true,
            active: true,
            label: `System maintenance until ${clock(window.endsAt)} (${formatRemaining(endsMs - nowMs)} left). Keep working — everything syncs afterwards.`,
        };
    }

    return {
        visible: true,
        active: false,
        label: `Maintenance in ${formatRemaining(startsMs - nowMs)}: offline ${clock(window.startsAt)}–${clock(window.endsAt)}. Work done meanwhile syncs afterwards.`,
    };
}
