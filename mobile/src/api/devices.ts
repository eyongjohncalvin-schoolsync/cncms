import { apiClient } from './client';
import type { RegisterPushTokenRequestBody, RegisterPushTokenResponse } from '../types/api';

/**
 * POST /api/v1/devices/push-token — App\Http\Controllers\Api\PushTokenController.
 * See src/notifications/registerPushToken.ts for the fire-and-forget caller;
 * this module is just the plain HTTP call, same split as src/api/sync.ts.
 */
export async function registerPushTokenRequest(body: RegisterPushTokenRequestBody): Promise<RegisterPushTokenResponse> {
    const { data } = await apiClient.post<RegisterPushTokenResponse>('/devices/push-token', body);

    return data;
}
