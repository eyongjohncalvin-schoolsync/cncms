import { useEffect, useState } from 'react';

/** Whole seconds until `targetIso` (never negative), re-rendering every second. */
export function useCountdown(targetIso: string | null | undefined): number {
    const target = targetIso ? new Date(targetIso).getTime() : 0;
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!target) {
            return;
        }

        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [target]);

    return target ? Math.max(0, Math.floor((target - now) / 1000)) : 0;
}

/** "2d 03:14:05", or "03:14:05" under a day. */
export function formatCountdown(totalSeconds: number): string {
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const clock = [hours, minutes, seconds].map((part) => String(part).padStart(2, '0')).join(':');

    return days > 0 ? `${days}d ${clock}` : clock;
}

/** Local date-time for a maintenance boundary, e.g. "Thu 22 Oct, 22:00". */
export function formatMaintenanceTime(iso: string, locale?: string): string {
    return new Date(iso).toLocaleString(locale, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
