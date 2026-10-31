import { apiClient } from './client';
import { API_BASE_URL } from './config';

/**
 * The manager inbox (docs/plans/mobile-manager-app.md, wave 1) — everything
 * waiting for this user's decision, from App\Services\ManagerInboxService,
 * and the decisions taken from it (App\Http\Controllers\Api\InboxController).
 * ONLINE-ONLY by design: a decision is never queued offline, since two
 * managers could decide the same item while disconnected.
 */
export type InboxSeverity = 'now' | 'today' | 'fyi';

export type InboxItemType =
    | 'voucher'
    | 'arrears_adjustment'
    | 'reversal_request'
    | 'complaint'
    | 'cash_alerts'
    | 'field_activations'
    | 'payments_to_verify'
    | 'opening_balances';

export type InboxAction = 'approve' | 'reject' | 'reverse' | 'decline' | 'assign';

export interface InboxItem {
    key: string;
    type: InboxItemType;
    uuid: string | null;
    title: string;
    subtitle: string;
    /** Decimal string in FCFA (negative for a decrease), or null. */
    amount: string | null;
    severity: InboxSeverity;
    created_at: string | null;
    /** Empty: decided on the website — open `web_path` there. */
    actions: InboxAction[];
    web_path: string;
}

export interface InboxResponse {
    data: InboxItem[];
    counts: { total: number; now: number };
}

export interface Technician {
    uuid: string;
    name: string;
    role: string;
    open: number;
    /** 'covers' = works this complaint's zone; 'anywhere' = no zone set; 'elsewhere'. */
    reason: 'covers' | 'anywhere' | 'elsewhere';
}

export async function fetchInbox(): Promise<InboxResponse> {
    const { data } = await apiClient.get<InboxResponse>('/inbox');

    return data;
}

/** The server's confirmation sentence, shown to the manager as is. */
type Done = Promise<string>;

async function post(path: string, body: Record<string, unknown> = {}): Done {
    const { data } = await apiClient.post<{ message?: string }>(path, body);

    return data.message ?? 'Done.';
}

export function approveVoucher(uuid: string, note?: string): Done {
    return post(`/inbox/vouchers/${uuid}/approve`, note ? { note } : {});
}

export function rejectVoucher(uuid: string, reason: string): Done {
    return post(`/inbox/vouchers/${uuid}/reject`, { reason });
}

export function approveArrearsAdjustment(uuid: string): Done {
    return post(`/inbox/arrears-adjustments/${uuid}/approve`);
}

export function rejectArrearsAdjustment(uuid: string, reason: string): Done {
    return post(`/inbox/arrears-adjustments/${uuid}/reject`, { rejection_reason: reason });
}

export function reversePayment(requestUuid: string, reason: string, refund: boolean): Done {
    return post(`/inbox/reversal-requests/${requestUuid}/reverse`, { reason, money: refund ? 'refund' : 'none' });
}

export function declineReversal(requestUuid: string, note: string): Done {
    return post(`/inbox/reversal-requests/${requestUuid}/decline`, { note });
}

export async function fetchTechnicians(complaintUuid: string): Promise<Technician[]> {
    const { data } = await apiClient.get<{ data: Technician[] }>(`/inbox/complaints/${complaintUuid}/technicians`);

    return data.data;
}

export async function assignComplaint(complaintUuid: string, assigneeUuid: string, assigneeName: string): Done {
    await apiClient.post(`/complaints/${complaintUuid}/assign`, { assignee_uuid: assigneeUuid });

    return `Assigned to ${assigneeName}.`;
}

/** The website page for an item (same server as the API, without /api/v1). */
export function webUrl(path: string): string {
    return API_BASE_URL.replace(/\/api\/v1\/?$/, '') + path;
}
