import { apiClient } from './client';
import type { PaymentByOthersApi } from '../types/api';

/**
 * "Ask the office to reverse" a synced payment (owner, 2026-10-28) — the
 * collector never undoes money themselves. POST
 * /api/v1/payments/{uuid}/reversal-request
 * (App\Http\Controllers\Api\PaymentReversalRequestController). Needs signal;
 * throws so the screen can say why.
 */
export async function requestReversal(serverUuid: string, reason: string): Promise<void> {
    await apiClient.post(`/payments/${serverUuid}/reversal-request`, { reason });
}

/**
 * This month's payments in the agent's zones that someone else took — at
 * the office, or another collector (Api\PaymentController::byOthers()).
 * Resolves `null` when the server can't be asked (offline, an older server).
 */
export async function fetchPaymentsByOthers(): Promise<PaymentByOthersApi[] | null> {
    try {
        const { data } = await apiClient.get<{ data: PaymentByOthersApi[] }>('/payments/by-others', { timeout: 8_000 });

        return data.data;
    } catch {
        return null;
    }
}
