import { apiClient } from './client';

/**
 * The daily income form (docs/plans/cash-controls.md, wave 4) —
 * App\Http\Controllers\Api\RemittanceController. Online only: a handover
 * happens face to face at the office.
 */

export type MethodTotals = { cash: string; mtn_momo: string; orange_money: string; bank: string };

export interface RemittancePreview {
    totals: MethodTotals;
    count: number;
    oldest_at: string | null;
    payments: Array<{
        uuid: string;
        customer_name: string | null;
        amount: string;
        method: 'cash' | 'mtn_momo' | 'orange_money' | 'bank';
        method_reference: string | null;
        receipt_number: string | null;
    }>;
    denominations: number[];
}

export interface RemittanceSummary {
    uuid: string;
    number: string;
    status: 'submitted' | 'received';
    expected_cash: string;
    declared_cash: string;
    received_cash: string | null;
    cash_variance: string | null;
    shortage_status: 'open' | 'repaid' | 'written_off' | null;
    submitted_at: string | null;
}

export async function fetchRemittancePreview(): Promise<RemittancePreview> {
    const { data } = await apiClient.get<{ data: RemittancePreview }>('/remittances/preview');

    return data.data;
}

export async function fetchMyRemittances(): Promise<RemittanceSummary[]> {
    const { data } = await apiClient.get<{ data: RemittanceSummary[] }>('/remittances');

    return data.data;
}

export async function submitRemittance(body: { declared_cash: number; denominations: Record<string, number> | null; note: string | null }): Promise<RemittanceSummary> {
    const { data } = await apiClient.post<{ data: RemittanceSummary }>('/remittances', body);

    return data.data;
}
