import { apiClient } from './client';
import type { RemoteComplaintLifecycleStatus } from './complaints';

/** Which complaints the desk lists (GET /api/v1/complaints?view=…). */
export type DeskView = 'unassigned' | 'open' | 'mine';

interface PersonRef {
    uuid: string;
    name: string;
}

export interface DeskComplaint {
    uuid: string;
    category: 'operational' | 'customer';
    title: string;
    description: string;
    urgent: boolean;
    status: RemoteComplaintLifecycleStatus;
    customer_name?: string | null;
    zone_name?: string | null;
    submitted_by?: PersonRef | null;
    assigned_to?: PersonRef | null;
    resolved_at: string | null;
    resolution_notes: string | null;
    escalated_at: string | null;
    created_at: string | null;
}

/** What the signed-in person may do with one complaint — the server's own policy answers. */
export interface DeskPermissions {
    assign: boolean;
    /** A technician may claim it: open, nobody on it. Absent from older servers. */
    take?: boolean;
    start: boolean;
    resolve: boolean;
}

/**
 * The complaint desk and "My jobs" (docs/plans/mobile-manager-app.md,
 * wave 3). Online only, like every decision on the phone: complaint sync is
 * create-only (see ./complaints.ts), so the desk always reads the server.
 */
export async function fetchDesk(view: DeskView): Promise<DeskComplaint[]> {
    const { data } = await apiClient.get<{ data: DeskComplaint[] }>('/complaints', { params: { view, per_page: 100 } });

    return data.data;
}

export async function fetchDeskComplaint(uuid: string): Promise<{ complaint: DeskComplaint; can: DeskPermissions }> {
    const { data } = await apiClient.get<{ data: DeskComplaint; can: DeskPermissions }>(`/complaints/${uuid}`);

    return { complaint: data.data, can: data.can };
}

/** "I'll take it" — the first technician to press it gets the job. */
export async function takeComplaint(uuid: string): Promise<DeskComplaint> {
    const { data } = await apiClient.post<{ data: DeskComplaint }>(`/complaints/${uuid}/take`);

    return data.data;
}

export async function startComplaint(uuid: string): Promise<DeskComplaint> {
    const { data } = await apiClient.post<{ data: DeskComplaint }>(`/complaints/${uuid}/start`);

    return data.data;
}

export async function resolveComplaint(uuid: string, notes: string): Promise<DeskComplaint> {
    const { data } = await apiClient.post<{ data: DeskComplaint }>(`/complaints/${uuid}/resolve`, { resolution_notes: notes });

    return data.data;
}