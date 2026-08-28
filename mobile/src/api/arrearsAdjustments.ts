import { apiClient } from './client';
import type { RequestArrearsAdjustmentPayload, RequestArrearsAdjustmentResponse } from '../types/api';

/**
 * POST /api/v1/arrears-adjustments —
 * App\Http\Controllers\Api\ArrearsAdjustmentController::store(). Creates a
 * maker-checker REQUEST only; the office still has to approve it (or a
 * second, more senior approver, depending on amount/reason/repeat-customer)
 * before it has any real effect on the customer's ledger — see
 * references/arrears-adjustment.md. There is no approve()/reject() call in
 * this module by design: that review workflow stays web-only, matching this
 * app's "mobile creates, web reviews" convention for payments/expenditures/
 * complaints/disconnections (mobile-app-react-native.md).
 *
 * ArrearsAdjustmentPolicy::create() is ungated for all 5 tenant roles
 * (confirmed by reading the real policy before building this), so this
 * function has no role gate of its own — every mobile caller may use it.
 */
export async function requestArrearsAdjustment(
    payload: RequestArrearsAdjustmentPayload,
): Promise<RequestArrearsAdjustmentResponse> {
    const { data } = await apiClient.post<RequestArrearsAdjustmentResponse>('/arrears-adjustments', payload);

    return data;
}
