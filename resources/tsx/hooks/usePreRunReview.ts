import { useCallback, useEffect, useState } from 'react';
import type { PreRunReviewResponse } from '@/types';

interface UsePreRunReviewResult {
    loading: boolean;
    error: string | null;
    data: PreRunReviewResponse | null;
    reload: () => void;
}

/**
 * Fetches GET /manuscripts/pre-run-review on demand — the "who hasn't paid"
 * pre-run review list (App\Http\Controllers\ManuscriptController::
 * preRunReview()'s doc comment). Deliberately plain `fetch()`, not an
 * Inertia visit: this is a small on-demand JSON call feeding a modal/panel,
 * not a page navigation, and must not touch the rest of the current page's
 * Inertia props.
 *
 * Only fetches while `active` is true (the Calculate confirm modal is open,
 * or the run-review screen is showing a `pending_review` run) — never on
 * page load or on every filter change, per the design ask. Re-fetches
 * whenever period/zoneUuid change while active, and whenever `reload()` is
 * called (the panel's "Refresh list" action).
 */
export function usePreRunReview(period: string, zoneUuid: string | undefined, active: boolean): UsePreRunReviewResult {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [data, setData] = useState<PreRunReviewResponse | null>(null);
    const [reloadToken, setReloadToken] = useState(0);

    const reload = useCallback(() => setReloadToken((n) => n + 1), []);

    useEffect(() => {
        if (!active) {
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setError(null);

        const params = new URLSearchParams({ period });
        if (zoneUuid) {
            params.set('zone_uuid', zoneUuid);
        }

        fetch(`/manuscripts/pre-run-review?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    const body = await response.json().catch(() => null);
                    throw new Error(body?.message ?? 'Could not load the pre-run review list.');
                }
                return response.json() as Promise<PreRunReviewResponse>;
            })
            .then((result) => setData(result))
            .catch((err: unknown) => {
                if (err instanceof DOMException && err.name === 'AbortError') {
                    return;
                }
                setError(err instanceof Error ? err.message : 'Could not load the pre-run review list.');
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [period, zoneUuid, active, reloadToken]);

    return { loading, error, data, reload };
}
