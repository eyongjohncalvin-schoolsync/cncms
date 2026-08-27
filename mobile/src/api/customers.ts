import { apiClient } from './client';
import type {
    CustomerDetailResponse,
    DisconnectCustomerRequestBody,
    DisconnectCustomerResponse,
    EligibleForDisconnectionResponse,
    ReconnectCustomerRequestBody,
    ReconnectCustomerResponse,
} from '../types/api';

/**
 * GET /api/v1/customers/{uuid} — live customer detail (arrears/credit/
 * recent payments/reconnection_fine), used by the Customer Detail screen
 * to enrich the offline SQLite cache with server-computed figures. See
 * mobile-app-react-native.md §4. Always requires connectivity — there is
 * no offline fallback for this specific call; callers should catch
 * network errors and fall back to the locally cached LocalCustomer fields.
 */
export async function fetchCustomerDetail(uuid: string): Promise<CustomerDetailResponse> {
    const { data } = await apiClient.get<CustomerDetailResponse>(`/customers/${uuid}`);

    return data;
}

/**
 * PATCH /api/v1/customers/{uuid}/reconnect — online-only (see
 * app/Services/CustomerStatusService.php::reconnectOne()): this is a status
 * transition plus server-side Payment creation guarded by validation
 * against the customer's CURRENT server-side status, so it deliberately
 * does NOT go through the offline /sync/push payments-array protocol (no
 * local_uuid idempotency exists for it, and queuing a status transition
 * offline could easily race a change made from the web admin panel in the
 * meantime). Restricted server-side to super/admin/manager
 * (App\Policies\CustomerPolicy::reconnect()) — an `agent`-role caller will
 * get a 403, which is left to propagate to the caller per api/client.ts's
 * convention, not treated as an auth failure.
 */
export async function reconnectCustomer(
    uuid: string,
    body: ReconnectCustomerRequestBody,
): Promise<ReconnectCustomerResponse> {
    const { data } = await apiClient.patch<ReconnectCustomerResponse>(`/customers/${uuid}/reconnect`, body);

    return data;
}

/**
 * PATCH /api/v1/customers/{uuid}/disconnect — same online-only reasoning as
 * reconnectCustomer() above (status transition guarded by CURRENT
 * server-side status, no local_uuid idempotency), so this also does NOT go
 * through the offline /sync/push protocol. 2026-08 mobile field-ops
 * widening: App\Policies\CustomerPolicy::disconnect() now admits an
 * `agent` scoped to their own zone (App\Support\TenantContext::zoneId),
 * alongside the unrestricted super/admin/manager — an agent calling this
 * for a customer outside their own zone still gets a 403.
 */
export async function disconnectCustomer(
    uuid: string,
    body: DisconnectCustomerRequestBody,
): Promise<DisconnectCustomerResponse> {
    const { data } = await apiClient.patch<DisconnectCustomerResponse>(`/customers/${uuid}/disconnect`, body);

    return data;
}

/**
 * GET /api/v1/customers/eligible-for-disconnection — the "flagged for
 * non-payment" list backing app/disconnections.tsx. Live/computed each
 * call (App\Services\CustomerEligibilityService is not persisted/cached),
 * so this is deliberately online-only with no offline fallback, same as
 * fetchCustomerDetail() above. Deliberately takes no zone_uuid parameter:
 * the server force-scopes an `agent` caller to their own zone regardless
 * of what's requested (App\Http\Controllers\Api\CustomerController::
 * eligibleForDisconnection()), so there is nothing for this client to
 * usefully pass — every mobile caller of this function is an agent seeing
 * only their own zone.
 */
export async function fetchEligibleForDisconnection(): Promise<EligibleForDisconnectionResponse> {
    const { data } = await apiClient.get<EligibleForDisconnectionResponse>('/customers/eligible-for-disconnection');

    return data;
}
