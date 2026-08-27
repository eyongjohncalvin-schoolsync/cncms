import { apiClient } from './client';
import type {
    LoginResponse,
    MeResponse,
    UpdatePasswordPayload,
    UpdateProfilePayload,
    UpdateProfileResponse,
} from '../types/api';

export async function login(identifier: string, password: string): Promise<LoginResponse> {
    // Accept either an email or a username in the same field, mirroring
    // AuthController::login()'s email/username duality — we send it as
    // `email` when it looks like one, `username` otherwise.
    const isEmail = identifier.includes('@');

    const { data } = await apiClient.post<LoginResponse>('/auth/login', {
        [isEmail ? 'email' : 'username']: identifier,
        password,
    });

    return data;
}

export async function fetchMe(): Promise<MeResponse> {
    const { data } = await apiClient.get<MeResponse>('/auth/me');

    return data;
}

export async function logout(): Promise<void> {
    await apiClient.post('/auth/logout');
}

/** PATCH /auth/profile — self-service name/username/email update. See
 * AuthContext.tsx's updateCachedUser() for how the result is reflected
 * locally without a re-login. */
export async function updateProfile(payload: UpdateProfilePayload): Promise<UpdateProfileResponse> {
    const { data } = await apiClient.patch<UpdateProfileResponse>('/auth/profile', payload);

    return data;
}

/** PATCH /auth/password — self-service password change. On success the
 * server revokes every OTHER active token for this account (see
 * AuthController::updatePassword()'s doc comment) — this device's own
 * token, the one used to make this call, stays valid, so no local
 * session/token handling is needed here beyond the normal request. */
export async function updatePassword(payload: UpdatePasswordPayload): Promise<void> {
    await apiClient.patch('/auth/password', payload);
}
