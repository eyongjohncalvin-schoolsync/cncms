import { apiClient } from './client';
import { API_BASE_URL } from './config';
import { getCurrentToken } from '../auth/tokenState';
import type {
    CreateSupplierPayload,
    ExpenditureListResponse,
    ExpenditureResponse,
    ExpenseLimitsResponse,
    ReceiptSource,
    SupplierListResponse,
    SupplierResponse,
} from '../types/api';

/**
 * Expense controls (2026-10) — the live, online-only side of expenses.
 * Recording an expense itself still goes through the offline outbox
 * (src/db/expenditures.ts + SyncManager push); everything here is either
 * reference data that SyncManager caches for offline use (limits,
 * suppliers) or an action that needs the server right now (approve,
 * reject, void, add receipt, create supplier).
 */

/** GET /resources/expense-limits — cached by SyncManager in sync_meta. */
export async function fetchExpenseLimits(): Promise<ExpenseLimitsResponse> {
    const { data } = await apiClient.get<ExpenseLimitsResponse>('/resources/expense-limits');

    return data;
}

/** GET /resources/suppliers — cached by SyncManager in the `suppliers` table. */
export async function fetchSuppliers(): Promise<SupplierListResponse> {
    const { data } = await apiClient.get<SupplierListResponse>('/resources/suppliers');

    return data;
}

/** POST /resources/suppliers — online only; the new supplier starts 'pending'
 * until the office approves it. */
export async function createSupplier(payload: CreateSupplierPayload): Promise<SupplierResponse> {
    const { data } = await apiClient.post<SupplierResponse>('/resources/suppliers', payload);

    return data;
}

/** GET /resources/expenditures?mine=1 — this user's own expenses with their
 * server-side status, newest first. */
export async function fetchMyExpenditures(perPage = 50): Promise<ExpenditureListResponse> {
    const { data } = await apiClient.get<ExpenditureListResponse>('/resources/expenditures', {
        params: { mine: 1, per_page: perPage },
    });

    return data;
}

export async function fetchExpenditure(uuid: string): Promise<ExpenditureResponse> {
    const { data } = await apiClient.get<ExpenditureResponse>(`/resources/expenditures/${uuid}`);

    return data;
}

/** GET /resources/approvals — what this manager can act on right now. */
export async function fetchApprovals(): Promise<ExpenditureListResponse> {
    const { data } = await apiClient.get<ExpenditureListResponse>('/resources/approvals');

    return data;
}

export async function approveExpenditure(uuid: string): Promise<ExpenditureResponse> {
    const { data } = await apiClient.post<ExpenditureResponse>(`/resources/expenditures/${uuid}/approve`);

    return data;
}

export async function rejectExpenditure(uuid: string, reason: string): Promise<ExpenditureResponse> {
    const { data } = await apiClient.post<ExpenditureResponse>(`/resources/expenditures/${uuid}/reject`, { reason });

    return data;
}

export async function voidExpenditure(uuid: string, reason: string): Promise<ExpenditureResponse> {
    const { data } = await apiClient.post<ExpenditureResponse>(`/resources/expenditures/${uuid}/void`, { reason });

    return data;
}

/**
 * POST /resources/expenditures/{uuid}/receipt — "Add receipt" on an expense
 * that is waiting for its photo, sent straight away when online. The
 * offline fallback is the sync outbox (/sync/upload-receipt via
 * SyncManager), not this call.
 */
export async function attachExpenditureReceipt(
    uuid: string,
    localUri: string,
    source: ReceiptSource,
    capturedAt: string,
): Promise<ExpenditureResponse> {
    const formData = new FormData();
    // React Native's FormData accepts this { uri, name, type } shape in
    // place of a Blob/File — the DOM typings don't model it, hence the cast.
    formData.append('receipt', { uri: localUri, name: 'receipt.jpg', type: 'image/jpeg' } as unknown as Blob);
    formData.append('source', source);
    formData.append('captured_at', capturedAt);

    const { data } = await apiClient.post<ExpenditureResponse>(`/resources/expenditures/${uuid}/receipt`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });

    return data;
}

/**
 * `{ uri, headers }` for an <Image> showing a private receipt photo
 * (ExpenditureResource.receipt_url is an authorised route, not a public
 * file). Relative URLs are resolved against the API host. Null when there
 * is no receipt or no session token.
 */
export function authorisedImageSource(url: string | null | undefined): { uri: string; headers: Record<string, string> } | null {
    const token = getCurrentToken();

    if (!url || !token) {
        return null;
    }

    const absolute = /^https?:\/\//i.test(url) ? url : `${API_BASE_URL.replace(/\/api\/v1\/?$/, '')}${url.startsWith('/') ? '' : '/'}${url}`;

    return { uri: absolute, headers: { Authorization: `Bearer ${token}`, Accept: 'image/*' } };
}
