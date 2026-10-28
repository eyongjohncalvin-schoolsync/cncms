import { apiClient } from './client';
import type { PaymentCheckApi } from '../types/api';

/** Long enough on 3G, short enough that a weak signal never holds up recording a payment. */
const CHECK_TIMEOUT_MS = 5_000;

/**
 * Before a payment is saved, when the phone has signal (owner, 2026-10-28):
 * has anyone — the office, another collector, this agent on another phone —
 * already recorded one for this customer that this payment may repeat?
 * GET /api/v1/customers/{uuid}/payment-check
 * (App\Http\Controllers\Api\PaymentController::check()).
 *
 * Resolves `undefined` when the server can't be asked (offline, timeout, an
 * older server): the caller falls back to what the phone knows.
 */
export async function checkEarlierPayment(customerUuid: string, amount: number): Promise<PaymentCheckApi['earlier'] | undefined> {
    try {
        const { data } = await apiClient.get<{ data: PaymentCheckApi }>(`/customers/${customerUuid}/payment-check`, {
            params: { amount },
            timeout: CHECK_TIMEOUT_MS,
        });

        return data.data.earlier;
    } catch {
        return undefined;
    }
}
