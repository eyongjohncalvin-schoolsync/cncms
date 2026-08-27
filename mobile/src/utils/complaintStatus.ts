import type { BadgeTone } from '../components/ui/Badge';
import type { LocalComplaint } from '../types/db';
import type { RemoteComplaintLifecycleStatus, RemoteComplaintStatus } from '../api/complaints';

export type { RemoteComplaintLifecycleStatus, RemoteComplaintStatus } from '../api/complaints';

/**
 * `App\Models\Complaint`'s real status enum (complaint-desk.md §2) — only
 * 3 values. "Reopened" is NOT a 4th status: App\Services\ComplaintService::
 * reopen() just sets status back to 'open' and clears the resolution
 * fields (confirmed by reading it), so a reopened complaint is
 * indistinguishable from a never-yet-worked one here, same as the web
 * app's own complaintState.ts treats it.
 */
export const COMPLAINT_STATUS_BADGE_TONE: Record<RemoteComplaintLifecycleStatus, BadgeTone> = {
    // 'pending' is this app's existing neutral/slate tone (see Badge.tsx) —
    // matches the web app's "new" state being slate, not amber (amber is
    // reserved elsewhere in this app exclusively for "saved on this
    // device, not yet synced" — reusing it here for "not yet resolved"
    // would blur two unrelated meanings under the same color).
    open: 'pending',
    // Blue, matching the web app's in_progress tone by hue family.
    in_progress: 'syncing',
    resolved: 'verified',
};

export const COMPLAINT_STATUS_LABEL: Record<RemoteComplaintLifecycleStatus, string> = {
    open: 'Open',
    in_progress: 'In Progress',
    resolved: 'Resolved',
};

export interface ComplaintListRow {
    localUuid: string;
    serverUuid: string | null;
    category: LocalComplaint['category'];
    title: string;
    description: string | null;
    urgent: boolean;
    createdAt: string;
    /** This DEVICE's own push state — always known locally, regardless of
     * whether a live status fetch has ever succeeded. */
    syncStatus: LocalComplaint['sync_status'];
    syncError: string | null;
    /**
     * The office's real lifecycle status. Null whenever it genuinely isn't
     * known yet on this device — either this complaint hasn't synced to
     * the server at all, or it has but no live status fetch
     * (fetchComplaintStatuses()) has succeeded this session. Deliberately
     * never defaulted to 'open': guessing would misrepresent something
     * this device does not actually know.
     */
    lifecycleStatus: RemoteComplaintLifecycleStatus | null;
    resolutionNotes: string | null;
}

/**
 * Pure merge of one local outbox row with the best currently-known remote
 * status (a Map keyed by server_uuid, built from fetchComplaintStatuses()'s
 * result) — split out from app/complaints.tsx so this join logic is
 * unit-testable without expo-sqlite, same "pure logic in src/utils, DB/
 * screen glue elsewhere" split as src/utils/complaintOutbox.ts.
 */
export function buildComplaintListRow(
    local: LocalComplaint,
    remoteByServerUuid: ReadonlyMap<string, RemoteComplaintStatus>,
): ComplaintListRow {
    const remote = local.server_uuid ? (remoteByServerUuid.get(local.server_uuid) ?? null) : null;

    return {
        localUuid: local.local_uuid,
        serverUuid: local.server_uuid,
        category: local.category,
        title: local.title,
        description: local.description,
        urgent: local.urgent === 1,
        createdAt: local.created_at,
        syncStatus: local.sync_status,
        syncError: local.sync_error,
        lifecycleStatus: remote?.status ?? null,
        resolutionNotes: remote?.resolution_notes ?? null,
    };
}

export function buildComplaintListRows(
    locals: LocalComplaint[],
    remoteByServerUuid: ReadonlyMap<string, RemoteComplaintStatus>,
): ComplaintListRow[] {
    return locals.map((local) => buildComplaintListRow(local, remoteByServerUuid));
}
