import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import * as SecureStore from 'expo-secure-store';
import { login as apiLogin, logout as apiLogout, fetchMe } from '../api/auth';
import { setUnauthorizedHandler, isNetworkError } from '../api/client';
import { getCurrentToken, setCurrentToken } from './tokenState';
import { clearStoredToken, getStoredToken, setStoredToken } from './tokenStorage';
import type { TenantRole } from '../types/api';

export interface AuthUser {
    uuid: string;
    name: string;
    username: string;
    email: string;
}

interface AuthProfile {
    user: AuthUser;
    role: TenantRole;
}

const PROFILE_KEY = 'cncms_auth_profile';
const PROFILE_STORE_OPTIONS: SecureStore.SecureStoreOptions = {
    keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

async function readCachedProfile(): Promise<AuthProfile | null> {
    const raw = await SecureStore.getItemAsync(PROFILE_KEY, PROFILE_STORE_OPTIONS);

    return raw ? (JSON.parse(raw) as AuthProfile) : null;
}

async function writeCachedProfile(profile: AuthProfile): Promise<void> {
    await SecureStore.setItemAsync(PROFILE_KEY, JSON.stringify(profile), PROFILE_STORE_OPTIONS);
}

async function clearCachedProfile(): Promise<void> {
    await SecureStore.deleteItemAsync(PROFILE_KEY, PROFILE_STORE_OPTIONS);
}

type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated';

interface AuthContextValue {
    status: AuthStatus;
    user: AuthUser | null;
    role: TenantRole | null;
    /** True once /auth/me has confirmed the token online at least this session. */
    roleConfirmed: boolean;
    login: (identifier: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Auth flow per mobile-app-react-native.md §7:
 *   - login() -> POST /auth/login -> immediately follow with GET /auth/me
 *     for the authoritative role (the login response's `role` is
 *     display-only).
 *   - Token stored via expo-secure-store, WHEN_UNLOCKED_THIS_DEVICE_ONLY.
 *   - A confirmed-invalid token (401) blocks with a re-authenticate screen
 *     but NEVER wipes local SQLite — this provider only ever touches the
 *     token + cached profile, never the payments/expenditures/customers
 *     tables.
 *   - On cold start with a stored token but no network, the cached profile
 *     is trusted optimistically (an agent must be able to open the app and
 *     see their queued work offline) — /auth/me is still attempted in the
 *     background and only forces a logout on an actual 401, not a network
 *     failure.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
    const [status, setStatus] = useState<AuthStatus>('loading');
    const [user, setUser] = useState<AuthUser | null>(null);
    const [role, setRole] = useState<TenantRole | null>(null);
    const [roleConfirmed, setRoleConfirmed] = useState(false);

    const handleUnauthorized = useCallback(() => {
        setCurrentToken(null);
        void clearStoredToken();
        void clearCachedProfile();
        setUser(null);
        setRole(null);
        setRoleConfirmed(false);
        setStatus('unauthenticated');
    }, []);

    useEffect(() => {
        setUnauthorizedHandler(handleUnauthorized);

        return () => setUnauthorizedHandler(null);
    }, [handleUnauthorized]);

    useEffect(() => {
        void (async () => {
            const token = await getStoredToken();

            if (!token) {
                setStatus('unauthenticated');
                return;
            }

            setCurrentToken(token);

            const cached = await readCachedProfile();

            if (cached) {
                setUser(cached.user);
                setRole(cached.role);
                setStatus('authenticated');
            }

            try {
                const me = await fetchMe();
                setUser(me.user);
                setRole(me.role);
                setRoleConfirmed(true);
                setStatus('authenticated');
                await writeCachedProfile({ user: me.user, role: me.role });
            } catch (error) {
                if (isNetworkError(error)) {
                    // Can't confirm right now — if we had a cached profile
                    // we're already showing it; if not, there is nothing
                    // safe to show, so fall back to the login screen.
                    if (!cached) {
                        setStatus('unauthenticated');
                    }
                }
                // A genuine 401 already routed through handleUnauthorized
                // via the response interceptor — nothing else to do here.
            }
        })();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const login = useCallback(async (identifier: string, password: string) => {
        const loginResponse = await apiLogin(identifier, password);

        setCurrentToken(loginResponse.token);
        await setStoredToken(loginResponse.token);

        // The login response's `role` is display-only — always resolve the
        // authoritative role via /auth/me before treating the user as
        // fully authenticated for permission purposes.
        const me = await fetchMe();

        setUser(me.user);
        setRole(me.role);
        setRoleConfirmed(true);
        await writeCachedProfile({ user: me.user, role: me.role });
        setStatus('authenticated');
    }, []);

    const logout = useCallback(async () => {
        try {
            await apiLogout();
        } catch {
            // Best-effort — even if the network call fails, proceed to
            // clear local session state. Local SQLite data is deliberately
            // left untouched by this action; only an explicit, separate
            // "switch agent" flow (not built in this phase) would ever
            // clear it.
        }

        setCurrentToken(null);
        await clearStoredToken();
        await clearCachedProfile();
        setUser(null);
        setRole(null);
        setRoleConfirmed(false);
        setStatus('unauthenticated');
    }, []);

    const value = useMemo<AuthContextValue>(
        () => ({ status, user, role, roleConfirmed, login, logout }),
        [status, user, role, roleConfirmed, login, logout],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const ctx = useContext(AuthContext);

    if (!ctx) {
        throw new Error('useAuth must be used within AuthProvider');
    }

    return ctx;
}

/** Non-hook accessor for modules outside the component tree (e.g. checking
 * whether a token exists before deciding to start SyncManager). */
export function hasStoredTokenSync(): boolean {
    return getCurrentToken() !== null;
}
