import axios, { AxiosError } from 'axios';
import { API_BASE_URL } from './config';
import { getCurrentToken } from '../auth/tokenState';
import type { ApiErrorBody } from '../types/api';

/**
 * Shared axios instance. Two hard requirements from
 * mobile-app-react-native.md §7, both implemented here rather than at each
 * call site so no future screen can accidentally get this wrong:
 *
 *   - A 401 means "token invalid, re-authenticate" — routed to the login
 *     screen via `unauthorizedHandler`, but NEVER wipes local SQLite data.
 *     Sanctum tokens here are long-lived revocable strings, not short-lived
 *     JWTs, so there is no silent-refresh path to attempt first.
 *   - A 403 is NOT a logout trigger. It means "still authenticated, just
 *     not permitted for this specific action" (e.g. a worker without
 *     can_record_payments, or an agent outside their zone). It is left to
 *     propagate to the caller as a normal rejected promise so the calling
 *     screen can show an in-context message.
 */
export const apiClient = axios.create({
    baseURL: API_BASE_URL,
    timeout: 20000,
    headers: {
        Accept: 'application/json',
    },
});

apiClient.interceptors.request.use((config) => {
    const token = getCurrentToken();

    if (token) {
        config.headers = config.headers ?? {};
        config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
});

type UnauthorizedHandler = () => void;

let unauthorizedHandler: UnauthorizedHandler | null = null;

/** Set once by AuthProvider on mount; see app/_layout.tsx. */
export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
    unauthorizedHandler = handler;
}

apiClient.interceptors.response.use(
    (response) => response,
    (error: AxiosError<ApiErrorBody>) => {
        if (error.response?.status === 401) {
            unauthorizedHandler?.();
        }

        // 403 is deliberately NOT handled here — it must reach the caller
        // as a normal error so the screen can render a plain-language
        // "not permitted" message without touching auth state at all.

        return Promise.reject(error);
    },
);

/** Plain-language error extraction, per mobile-app-react-native.md §6:
 * "Couldn't reach the server — this payment is saved and will sync when
 * you're back online," never "Network request failed." Screens that need
 * a network-vs-server distinction should check `isNetworkError` first.
 */
export function isNetworkError(error: unknown): boolean {
    return axios.isAxiosError(error) && !error.response;
}

export function extractErrorMessage(error: unknown, fallback = 'Something went wrong.'): string {
    if (isNetworkError(error)) {
        return "Couldn't reach the server.";
    }

    if (axios.isAxiosError<ApiErrorBody>(error) && error.response?.data?.message) {
        return error.response.data.message;
    }

    return fallback;
}
