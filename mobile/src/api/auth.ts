import * as Device from 'expo-device';
import { apiClient } from './client';
import { getOrCreateDeviceId } from '../db/syncMeta';
import type {
    LoginResponse,
    LoginResult,
    MeResponse,
    SecurityOverview,
    SessionsResponse,
    TwoFactorMethod,
    TwoFactorSetup,
    UpdatePasswordPayload,
    UpdateProfilePayload,
    UpdateProfileResponse,
} from '../types/api';

/**
 * The name this phone's token gets in "Signed-in devices" (web My account
 * and the app's own Devices screen), e.g. "TECNO KI5q". Falls back to the
 * server default when the model is unknown.
 */
function deviceName(): string | undefined {
    const name = [Device.manufacturer, Device.modelName].filter(Boolean).join(' ').trim();

    return name === '' ? undefined : name.slice(0, 100);
}

/**
 * POST /auth/login. With two-factor on, the answer is a challenge (no
 * token) — see TwoFactorChallengeResponse and verifyTwoFactor().
 */
export async function login(identifier: string, password: string): Promise<LoginResult> {
    // Accept either an email or a username in the same field, mirroring
    // AuthController::login()'s email/username duality — we send it as
    // `email` when it looks like one, `username` otherwise.
    const isEmail = identifier.includes('@');

    const { data } = await apiClient.post<LoginResult>('/auth/login', {
        [isEmail ? 'email' : 'username']: identifier,
        password,
        device_name: deviceName(),
    });

    return data;
}

/** POST /auth/two-factor — the second step; returns the token on success. */
export async function verifyTwoFactor(challengeToken: string, method: TwoFactorMethod, code: string): Promise<LoginResponse> {
    const { data } = await apiClient.post<LoginResponse>('/auth/two-factor', {
        challenge_token: challengeToken,
        method,
        code,
        device_name: deviceName(),
    });

    return data;
}

/** POST /auth/two-factor/email — "email me a code instead". */
export async function sendTwoFactorEmail(challengeToken: string): Promise<string> {
    const { data } = await apiClient.post<{ message: string }>('/auth/two-factor/email', { challenge_token: challengeToken });

    return data.message;
}

/** POST /auth/login-code — always the same answer, account or not. */
export async function sendLoginCode(identifier: string): Promise<string> {
    const { data } = await apiClient.post<{ message: string }>('/auth/login-code', { identifier });

    return data.message;
}

/** POST /auth/login-code/verify — a token, or a two-factor challenge. */
export async function verifyLoginCode(identifier: string, code: string): Promise<LoginResult> {
    const { data } = await apiClient.post<LoginResult>('/auth/login-code/verify', {
        identifier,
        code,
        device_name: deviceName(),
    });

    return data;
}

export async function fetchMe(): Promise<MeResponse> {
    const { data } = await apiClient.get<MeResponse>('/auth/me');

    return data;
}

/** Also unregisters this phone from push, so a shared phone stops getting
 * the signed-out person's alerts (manager inbox, wave 2). */
export async function logout(): Promise<void> {
    await apiClient.post('/auth/logout', { device_id: await getOrCreateDeviceId() });
}

/** PATCH /auth/profile — self-service name/username update. See
 * AuthContext.tsx's updateCachedUser() for how the result is reflected
 * locally without a re-login. */
export async function updateProfile(payload: UpdateProfilePayload): Promise<UpdateProfileResponse> {
    const { data } = await apiClient.patch<UpdateProfileResponse>('/auth/profile', payload);

    return data;
}

/** PATCH /auth/password — self-service password change. On success the
 * server revokes every OTHER active token and web session for this account
 * (see AuthController::updatePassword()) — this device's own token stays
 * valid, so no local session/token handling is needed here. */
export async function updatePassword(payload: UpdatePasswordPayload): Promise<void> {
    await apiClient.patch('/auth/password', payload);
}

/** GET /auth/security — two-factor status and any pending email change. */
export async function fetchSecurity(): Promise<SecurityOverview> {
    const { data } = await apiClient.get<SecurityOverview>('/auth/security');

    return data;
}

/** POST /auth/email — emails a code to the NEW address. */
export async function requestEmailChange(email: string, currentPassword: string): Promise<void> {
    await apiClient.post('/auth/email', { email, current_password: currentPassword });
}

/** POST /auth/email/confirm — returns the account's (new) email. */
export async function confirmEmailChange(code: string): Promise<string> {
    const { data } = await apiClient.post<{ email: string }>('/auth/email/confirm', { code });

    return data.email;
}

export async function cancelEmailChange(): Promise<void> {
    await apiClient.delete('/auth/email');
}

/** POST /auth/two-factor/setup — a fresh secret (2FA still off). */
export async function setupTwoFactor(): Promise<TwoFactorSetup> {
    const { data } = await apiClient.post<TwoFactorSetup>('/auth/two-factor/setup');

    return data;
}

/** POST /auth/two-factor/confirm — turns 2FA on; recovery codes shown once. */
export async function confirmTwoFactor(code: string): Promise<string[]> {
    const { data } = await apiClient.post<{ recovery_codes: string[] }>('/auth/two-factor/confirm', { code });

    return data.recovery_codes;
}

export async function cancelTwoFactorSetup(): Promise<void> {
    await apiClient.delete('/auth/two-factor/setup');
}

/** DELETE /auth/two-factor — password + authenticator or recovery code. */
export async function disableTwoFactor(password: string, code: string): Promise<void> {
    await apiClient.delete('/auth/two-factor', { data: { password, code } });
}

export async function regenerateRecoveryCodes(password: string): Promise<string[]> {
    const { data } = await apiClient.post<{ recovery_codes: string[] }>('/auth/two-factor/recovery-codes', { password });

    return data.recovery_codes;
}

/** GET /auth/sessions — web sessions + app devices. */
export async function fetchSessions(): Promise<SessionsResponse> {
    const { data } = await apiClient.get<SessionsResponse>('/auth/sessions');

    return data;
}

/** DELETE /auth/sessions — everything except this phone. */
export async function signOutOtherSessions(password: string): Promise<void> {
    await apiClient.delete('/auth/sessions', { data: { password } });
}
