import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import * as SecureStore from 'expo-secure-store';
import { login as apiLogin, logout as apiLogout, fetchMe } from '../api/auth';
import { setUnauthorizedHandler } from '../api/client';
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
    /**
     * RBAC v2 (docs/plans/rbac-v2-configurable-roles.md): the resolved
     * permission list for this session's role, `['*']` for a super role.
     * Cached on disk alongside `role` so it survives an offline cold start
     * exactly like `role` does; refreshed on every `/auth/me`. Optional on
     * the wire only for backward-compat with a profile written by a
     * pre-Wave-4 build — `readCachedProfile()` defaults a missing value to
     * `[]` (nothing granted until the next online `/auth/me` repopulates it).
     */
    permissions?: string[];
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
    /**
     * RBAC v2 Wave 4: the resolved permission list for `role` (`['*']` for
     * super), from `/auth/me` and cached offline alongside `role`. Prefer
     * the `can()` helper below over reading this directly.
     */
    permissions: string[];
    /**
     * True if the session holds `permission` (or is super, i.e. `['*']`).
     * The mobile counterpart of the web `hasPermission()` /
     * `TenantContext::can()` — a DISPLAY affordance; the server-side Policy
     * is the real gate on every write.
     */
    can: (permission: string) => boolean;
    /** True once /auth/me has confirmed the token online at least this session. */
    roleConfirmed: boolean;
    login: (identifier: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
    /** Merges a patch (e.g. the response of PATCH /auth/profile) into both
     * in-memory state and the cached profile on disk, so the display
     * refreshes immediately without a fresh /auth/me round-trip or a
     * re-login. See app/edit-profile.tsx. No-op if called before a user is
     * loaded (shouldn't happen — this is only ever called from an
     * already-authenticated screen). */
    updateCachedUser: (patch: Partial<AuthUser>) => Promise<void>;
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
    const [permissions, setPermissions] = useState<string[]>([]);
    const [roleConfirmed, setRoleConfirmed] = useState(false);

    const handleUnauthorized = useCallback(() => {
        setCurrentToken(null);
        void clearStoredToken();
        void clearCachedProfile();
        setUser(null);
        setRole(null);
        setPermissions([]);
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
                setPermissions(cached.permissions ?? []);
                setStatus('authenticated');
            }

            try {
                const me = await fetchMe();
                setUser(me.user);
                setRole(me.role);
                setPermissions(me.permissions);
                setRoleConfirmed(true);
                setStatus('authenticated');
                await writeCachedProfile({ user: me.user, role: me.role, permissions: me.permissions });
            } catch {
                // A genuine 401 is already fully handled by
                // handleUnauthorized(), invoked synchronously by the
                // response interceptor before this catch runs — nothing
                // else to do here for that case.
            } finally {
                // Guarantee `status` never gets stuck on 'loading', no
                // matter what shape the failure took: a network error, a
                // non-401 server error (5xx, 403, 422...), a malformed
                // response, a timeout, or any other exception `fetchMe()`
                // can throw — not just the ones we explicitly recognize.
                // If a cached profile is already on screen (status was set
                // above), keep trusting it rather than downgrading on an
                // unconfirmed failure. Otherwise there is nothing safe to
                // show, so send the agent to the login screen.
                setStatus((prev) => (prev === 'loading' ? 'unauthenticated' : prev));
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
        setPermissions(me.permissions);
        setRoleConfirmed(true);
        await writeCachedProfile({ user: me.user, role: me.role, permissions: me.permissions });
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
        setPermissions([]);
        setRoleConfirmed(false);
        setStatus('unauthenticated');
    }, []);

    const updateCachedUser = useCallback(
        async (patch: Partial<AuthUser>) => {
            if (!user || !role) {
                return;
            }

            const next = { ...user, ...patch };

            setUser(next);
            await writeCachedProfile({ user: next, role, permissions });
        },
        [user, role, permissions],
    );

    const can = useCallback(
        (permission: string) => permissions.includes('*') || permissions.includes(permission),
        [permissions],
    );

    const value = useMemo<AuthContextValue>(
        () => ({ status, user, role, permissions, can, roleConfirmed, login, logout, updateCachedUser }),
        [status, user, role, permissions, can, roleConfirmed, login, logout, updateCachedUser],
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
