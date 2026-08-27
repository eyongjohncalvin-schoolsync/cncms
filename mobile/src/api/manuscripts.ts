import { apiClient } from './client';
import type { ManuscriptIndexResponse } from '../types/api';

/**
 * A generous single-page size, not real pagination. An agent's own zone
 * runs to "low hundreds" of customers per mobile-app-react-native.md §2's
 * stated data scale (~549 customers total, tenant-wide) — this comfortably
 * covers a real zone in one request, matching disconnections.tsx's same
 * "no pagination UI needed at this scale" call. Office roles (manager/
 * admin/super) hitting this same endpoint are NOT zone-scoped and could in
 * principle exceed this in a larger tenant; manuscript.tsx detects that
 * case from the response's `meta.total` and discloses the truncation to the
 * viewer rather than silently dropping rows.
 */
const PAGE_SIZE = 300;

/**
 * Today's real calendar period as 'YYYY-MM', local device time — matches
 * `Carbon::now()->format('Y-m')` server-side exactly. Deliberately hand-
 * rolled (not `Date#toISOString()`, which is UTC and can roll to the wrong
 * calendar day/month near a month boundary) rather than derived from any
 * cached or server-supplied value — see fetchManuscripts()'s doc comment
 * for why this function always computes its own period rather than trusting
 * anything else.
 */
export function currentPeriod(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');

    return `${now.getFullYear()}-${month}`;
}

/**
 * GET /api/v1/manuscripts — App\Http\Controllers\Api\ManuscriptController::index().
 *
 * PERIOD SCOPING — deliberately explicit, never omitted. A real 2026-08
 * incident (see Customer::latestManuscript()'s own doc comment, and
 * mobile-app-react-native.md §13) found a "latest manuscript of any period"
 * relationship silently trusting 1,509 bogus future-dated rows as "current"
 * for every customer, corrupting arrears/total_bill figures app-wide. This
 * function always sends `period` as today's real calendar month
 * (`currentPeriod()`), even though the server
 * (App\Services\ManuscriptService::scopedFilters()) independently
 * defaults+validates to the exact same value when `period` is omitted —
 * belt-and-braces: this client is explicit about what period it means
 * rather than silently relying on a server default it never looks at, and
 * this is a plain calendar computation, never "whatever sorts highest."
 *
 * ZONE SCOPING — deliberately sends no zone_uuid. The server force-scopes
 * an `agent` caller to their own zone regardless of any zone_uuid sent
 * (App\Support\TenantContext::zoneId — added to
 * Api\ManuscriptController::index() alongside this screen, mirroring
 * CustomerController::eligibleForDisconnection()'s pre-existing pattern),
 * so there is nothing useful for this client to pass — same reasoning
 * fetchEligibleForDisconnection() documents in src/api/customers.ts. Office
 * roles (manager/admin/super) reaching this same call see every zone,
 * unfiltered, matching their existing web Manuscripts register behavior.
 *
 * Online-only, same as fetchEligibleForDisconnection() — these figures are
 * computed live server-side each call, not cached to local SQLite, so there
 * is no offline fallback.
 */
export async function fetchManuscripts(period: string = currentPeriod()): Promise<ManuscriptIndexResponse> {
    const { data } = await apiClient.get<ManuscriptIndexResponse>('/manuscripts', {
        params: { period, per_page: PAGE_SIZE },
    });

    return data;
}
