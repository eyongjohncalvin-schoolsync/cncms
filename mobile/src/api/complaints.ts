import { apiClient } from './client';

export type RemoteComplaintLifecycleStatus = 'open' | 'in_progress' | 'resolved';

export interface RemoteComplaintStatus {
    uuid: string;
    status: RemoteComplaintLifecycleStatus;
    resolution_notes: string | null;
    resolved_at: string | null;
}

interface ComplaintIndexApiItem {
    uuid: string;
    status: RemoteComplaintLifecycleStatus;
    resolution_notes: string | null;
    resolved_at: string | null;
}

interface ComplaintIndexApiResponse {
    data: ComplaintIndexApiItem[];
}

/**
 * GET /api/v1/complaints — best-effort, ONLINE-ONLY enrichment for the
 * Complaints history screen (app/complaints.tsx).
 *
 * Why this exists at all: complaint-desk.md §7 scoped v1 mobile to
 * submission-only, and complaint sync really is push/create-only —
 * confirmed by reading App\Services\SyncService::pull() (only
 * `customers`/`payments` come back) and src/sync/SyncManager.ts (complaints
 * only ever appear in the push batch, never reconciled from a pull). That
 * means the local `complaints` table (src/db/complaints.ts) has NO record
 * of a complaint's real lifecycle status (open/in_progress/resolved) or
 * resolution_notes — only this device's own push/sync_status. This call is
 * what actually answers "did the office resolve this?" using the JSON API
 * counterpart (App\Http\Controllers\Api\ComplaintController::index()) that
 * already exists for exactly this purpose per its own class doc ("also the
 * surface a future mobile submission screen would call").
 *
 * ComplaintPolicy::viewAny()/view() are unconditionally open to any
 * authenticated tenant user — "visibility is deliberately universal," per
 * that policy's class doc — so there is no "mine" filter param to pass;
 * matching a response row back to one of this device's own local rows
 * happens client-side by `server_uuid` (see src/utils/complaintStatus.ts).
 * `per_page=100` is a pragmatic cap matching this app's small-team scale
 * (complaint-desk.md repeatedly describes this feature as sized for "a
 * handful of agents"), not a paging UI — if a tenant ever has more than
 * 100 complaints outstanding this call simply won't enrich the overflow,
 * which degrades to the same honest "Submitted" placeholder used whenever
 * this call hasn't completed yet, never to a wrong answer.
 *
 * Deliberately NOT part of the offline sync protocol and NOT required for
 * the screen to render: the base list always comes from local SQLite first
 * (mobile-app-react-native.md §2's "screens query SQLite, the real source
 * of truth, always available offline"). This is called opportunistically
 * on top, fails silently when offline, and never blocks or delays the
 * list's initial paint — see app/complaints.tsx's own doc comment.
 */
export async function fetchComplaintStatuses(): Promise<RemoteComplaintStatus[]> {
    const { data } = await apiClient.get<ComplaintIndexApiResponse>('/complaints', {
        params: { per_page: 100, sort: 'created_at', direction: 'desc' },
    });

    return data.data.map((item) => ({
        uuid: item.uuid,
        status: item.status,
        resolution_notes: item.resolution_notes,
        resolved_at: item.resolved_at,
    }));
}
