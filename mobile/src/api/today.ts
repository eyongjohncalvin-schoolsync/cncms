import { apiClient } from './client';

export interface ZoneRow {
    uuid: string;
    name: string;
    collector: string | null;
    rate: number | null;
    paid_share: number | null;
    usual_paid_share: number | null;
}

export interface BalanceCheck {
    key: string;
    status: 'ok' | 'warning' | 'danger';
    title: string;
    detail: string;
}

export interface TodayOverview {
    today: string;
    collections: {
        total: string;
        count: number;
        by_method: { method: string; label: string; total: string; count: number }[];
        by_collector: { name: string; total: string; count: number }[];
    };
    month: { period: string; collected: string; collection_rate: number | null; arrears: string };
    zones: { period: string; judged: boolean; zones_count: number; best: ZoneRow[]; worst: ZoneRow[]; dropped: ZoneRow[] };
    balance: { recorded: string; with_collectors: string; checks: BalanceCheck[] };
}

export interface StaffMember {
    id: number;
    name: string;
    username: string | null;
    role: string;
    role_label: string;
    job_title: string | null;
    branch_name: string | null;
    status: string;
    sign_in_blocked_until: string | null;
    is_me: boolean;
    can_manage: boolean;
}

export interface StaffList {
    can_manage: boolean;
    roles: { name: string; label: string }[];
    staff: StaffMember[];
}

/**
 * Today and staff on the phone (docs/plans/mobile-manager-app.md, wave 5).
 * Today is read only; the staff actions use the Users Control Center's
 * rules. Online only.
 */
export async function fetchToday(): Promise<TodayOverview> {
    const { data } = await apiClient.get<{ data: TodayOverview }>('/today');

    return data.data;
}

export async function fetchStaff(): Promise<StaffList> {
    const { data } = await apiClient.get<{ data: StaffList }>('/staff');

    return data.data;
}

async function done(request: Promise<{ data: { message: string } }>): Promise<string> {
    return (await request).data.message;
}

export function changeRole(id: number, role: string): Promise<string> {
    return done(apiClient.patch(`/staff/${id}/role`, { role }));
}

export function deactivateStaff(id: number): Promise<string> {
    return done(apiClient.post(`/staff/${id}/deactivate`));
}

export function activateStaff(id: number): Promise<string> {
    return done(apiClient.post(`/staff/${id}/activate`));
}

export function unblockStaff(id: number): Promise<string> {
    return done(apiClient.post(`/staff/${id}/unblock`));
}
