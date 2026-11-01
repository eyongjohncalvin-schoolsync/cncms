import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import * as SecureStore from 'expo-secure-store';
import { useQueryClient } from '@tanstack/react-query';
import { Alert } from 'react-native';
import { login as apiLogin, logout as apiLogout, disableQuickSignIn, enableQuickSignIn, fetchMe, quickLogin, verifyLoginCode, verifyTwoFactor } from '../api/auth';
import { clearQuickSignIn, confirmFingerprint, getQuickSignIn, markOffered, saveQuickSignIn, wasOffered } from './quickSignIn';
import { setUnauthorizedHandler } from '../api/client';
import { getCurrentToken, setCurrentToken } from './tokenState';
import { clearStoredToken, getStoredToken, setStoredToken } from './tokenStorage';
import { isTwoFactorChallenge } from '../utils/twoFactor';
import { clearInbox } from '../inbox/inboxStore';
import { biometricAvailable, clearAppLock } from '../lock/appLock';
import { countUnsent, getDataOwner, hasLocalData, setDataOwner, wipeLocalData } from '../db/localData';
import { dataOwnerKey, decideOnSignIn } from '../utils/dataOwner';
import { syncManager } from '../sync/SyncManager';
import { refreshNotificationsState } from '../notifications/notificationStore';
import { PROFILE_KEY, PROFILE_STORE_OPTIONS } from './cachedUser';
import type { LoginResponse, LoginResult, MeResponse, TenantRole, TwoFactorChallengeResponse, TwoFactorMethod } from '../types/api';

/**
 * Someone else's records are still waiting on this phone: signing in as
 * another person would either show them or send them under the wrong
 * account. The sign-in is given up; the sign-in screen offers to delete
 * them (AuthContext's discardOtherData()).
 */
export class OtherPersonsDataError extends Error {
    constructor(public readonly unsent: number) {
        super(`This phone still has ${unsent} record${unsent === 1 ? '' : 's'} the previous person hasn't sent.`);
    }
}

export type SignInOutcome = { status: 'signed_in' } | { status: 'two_factor'; challenge: TwoFactorChallengeResponse };

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
    /**
     * Password sign-in. Resolves `two_factor` (with the challenge) instead of
     * signing in when the account has two-factor on — finish with
     * completeTwoFactor().
     */
    login: (identifier: string, password: string) => Promise<SignInOutcome>;
    /** Passwordless sign-in with an emailed code (same outcome shape). */
    loginWithCode: (identifier: string, code: string) => Promise<SignInOutcome>;
    /** The second sign-in step: authenticator, recovery or emailed code. */
    completeTwoFactor: (challengeToken: string, method: TwoFactorMethod, code: string) => Promise<void>;
    logout: () => Promise<void>;
    /** Merges a patch (e.g. the response of PATCH /auth/profile) into both
     * in-memory state and the cached profile on disk, so the display
     * refreshes immediately without a fresh /auth/me round-trip or a
     * re-login. See app/edit-profile.tsx. No-op if called before a user is
     * loaded (shouldn't happen — this is only ever called from an
     * already-authenticated screen). */
    updateCachedUser: (patch: Partial<AuthUser>) => Promise<void>;
    /**
     * Set when a sign-in was refused because the previous person's records
     * are still unsent on this phone (OtherPersonsDataError): how many.
     */
    otherPersonsUnsent: number | null;
    /** Deletes the previous person's unsent records so someone else can sign in. */
    discardOtherData: () => Promise<void>;
    /** Who can sign in on this phone with a fingerprint, if anyone (src/auth/quickSignIn.ts). */
    fingerprintSignIn: { name: string; userUuid: string } | null;
    /** Fingerprint, then signed in without the password; 'cancelled' when the prompt was dismissed. */
    loginWithFingerprint: () => Promise<'signed_in' | 'cancelled'>;
    /** Turns fingerprint sign-in on (asks for the fingerprint first) or off for the signed-in person. */
    setFingerprintSignIn: (enabled: boolean) => Promise<void>;
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

    const queryClient = useQueryClient();
    const [otherPersonsUnsent, setOtherPersonsUnsent] = useState<number | null>(null);
    const [fingerprintSignIn, setFingerprintSignInState] = useState<{ name: string; userUuid: string } | null>(null);

    useEffect(() => {
        void getQuickSignIn().then((stored) => setFingerprintSignInState(stored ? { name: stored.name, userUuid: stored.userUuid } : null));
    }, []);

    const turnOnFingerprint = useCallback(async (signedIn: AuthUser) => {
        if (!(await confirmFingerprint('Confirm your fingerprint'))) {
            return;
        }

        const { id, key } = await enableQuickSignIn();

        await saveQuickSignIn({ id, key, userUuid: signedIn.uuid, name: signedIn.name });
        setFingerprintSignInState({ name: signedIn.name, userUuid: signedIn.uuid });
    }, []);

    const turnOffFingerprint = useCallback(async () => {
        const stored = await getQuickSignIn();

        if (stored) {
            try {
                await disableQuickSignIn(stored.id);
            } catch {
                // Offline or already gone: the key leaves this phone either way.
            }
        }

        await clearQuickSignIn();
        setFingerprintSignInState(null);
    }, []);

    /**
     * After a password (or code) sign-in: a fingerprint sign-in left by
     * someone else stops working on this phone, and the person is offered
     * their own — once; Settings has the switch after that.
     */
    const afterPasswordSignIn = useCallback(
        async (signedIn: AuthUser) => {
            const stored = await getQuickSignIn();

            if (stored && stored.userUuid !== signedIn.uuid) {
                await clearQuickSignIn();
                setFingerprintSignInState(null);
            }

            if ((stored && stored.userUuid === signedIn.uuid) || !(await biometricAvailable()) || (await wasOffered(signedIn.uuid))) {
                return;
            }

            await markOffered(signedIn.uuid);

            Alert.alert('Sign in with your fingerprint next time?', "You won't need to type your password on this phone. You can turn it off in Settings.", [
                { text: 'Not now', style: 'cancel' },
                {
                    text: 'Turn on',
                    onPress: () => {
                        void turnOnFingerprint(signedIn).catch(() => Alert.alert('Not turned on', 'Try again from Settings when you are online.'));
                    },
                },
            ]);
        },
        [turnOnFingerprint],
    );

    /** Empties the phone of the previous account's data, on disk and in memory. */
    const resetLocalData = useCallback(async () => {
        await wipeLocalData();
        queryClient.clear();
        clearInbox();
        await refreshNotificationsState();
        await syncManager.refreshQueuedCounts();
    }, [queryClient]);

    /**
     * The phone's data must be this account's: company + person. Someone
     * else's is wiped first — or, when they left records unsent, the
     * sign-in is refused (OtherPersonsDataError) so those are never shown
     * to, or sent under, the wrong account.
     */
    const claimLocalData = useCallback(
        async (me: MeResponse) => {
            const owner = dataOwnerKey(me.workspace, me.user.uuid);
            const unsent = await countUnsent();
            const decision = decideOnSignIn(await getDataOwner(), owner, await hasLocalData(), unsent);

            if (decision === 'refuse') {
                setOtherPersonsUnsent(unsent);
                throw new OtherPersonsDataError(unsent);
            }

            if (decision === 'wipe') {
                await resetLocalData();
            }

            await setDataOwner(owner);
            setOtherPersonsUnsent(null);
        },
        [resetLocalData],
    );

    const discardOtherData = useCallback(async () => {
        await resetLocalData();
        setOtherPersonsUnsent(null);
    }, [resetLocalData]);

    const handleUnauthorized = useCallback(() => {
        // The phone's data stays (the same person usually signs straight
        // back in); the next sign-in checks whose it is.
        void syncManager.stopAndSettle();
        setCurrentToken(null);
        void clearStoredToken();
        void clearCachedProfile();
        clearInbox();
        // The next person to sign in on this phone sets their own PIN.
        void clearAppLock();
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

                // A phone from before data was keyed to its account (or
                // somehow holding another's): stop syncing, start clean
                // unless records are still waiting to go, then resume.
                if ((await getDataOwner()) !== dataOwnerKey(me.workspace, me.user.uuid)) {
                    await syncManager.stopAndSettle();

                    if ((await countUnsent()) === 0) {
                        await resetLocalData();
                    }

                    await setDataOwner(dataOwnerKey(me.workspace, me.user.uuid));
                    syncManager.start();
                }
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

    const finishLogin = useCallback(async (token: string, via: 'password' | 'fingerprint' = 'password') => {
        // Nothing of a previous session may still be syncing once this
        // token is in use.
        await syncManager.stopAndSettle();
        setCurrentToken(token);

        // The login response's `role` is display-only — always resolve the
        // authoritative role via /auth/me before treating the user as
        // fully authenticated for permission purposes.
        let me: MeResponse;

        try {
            me = await fetchMe();
            await claimLocalData(me);
        } catch (error) {
            if (error instanceof OtherPersonsDataError) {
                // Given up: this new sign-in ends on the server too.
                try {
                    await apiLogout();
                } catch {
                    // Best-effort, as in logout().
                }
            }

            setCurrentToken(null);
            throw error;
        }

        await setStoredToken(token);

        setUser(me.user);
        setRole(me.role);
        setPermissions(me.permissions);
        setRoleConfirmed(true);
        await writeCachedProfile({ user: me.user, role: me.role, permissions: me.permissions });
        setStatus('authenticated');

        if (via === 'password') {
            void afterPasswordSignIn(me.user);
        }
    }, [claimLocalData, afterPasswordSignIn]);

    const loginWithFingerprint = useCallback(async (): Promise<'signed_in' | 'cancelled'> => {
        const stored = await getQuickSignIn();

        if (!stored) {
            setFingerprintSignInState(null);
            throw new Error('Fingerprint sign-in is not set up on this phone. Sign in with your password.');
        }

        if (!(await confirmFingerprint(`Sign in as ${stored.name}`))) {
            return 'cancelled';
        }

        let response: LoginResponse;

        try {
            response = await quickLogin(stored.key);
        } catch (error) {
            // Turned off elsewhere, the password changed, or the account was
            // deactivated: the key is dead, so it leaves this phone.
            if ((error as { response?: { status?: number } }).response?.status === 401) {
                await clearQuickSignIn();
                setFingerprintSignInState(null);
            }

            throw error;
        }

        await finishLogin(response.token, 'fingerprint');

        return 'signed_in';
    }, [finishLogin]);

    const setFingerprintSignIn = useCallback(
        async (enabled: boolean) => {
            if (!enabled) {
                await turnOffFingerprint();

                return;
            }

            if (user) {
                await turnOnFingerprint(user);
            }
        },
        [user, turnOnFingerprint, turnOffFingerprint],
    );

    /** Signs in, or hands back the two-factor challenge still to pass. */
    const settle = useCallback(
        async (result: LoginResult): Promise<SignInOutcome> => {
            if (isTwoFactorChallenge(result)) {
                return { status: 'two_factor', challenge: result as TwoFactorChallengeResponse };
            }

            await finishLogin((result as LoginResponse).token);

            return { status: 'signed_in' };
        },
        [finishLogin],
    );

    const login = useCallback(
        async (identifier: string, password: string) => settle(await apiLogin(identifier, password)),
        [settle],
    );

    const loginWithCode = useCallback(
        async (identifier: string, code: string) => settle(await verifyLoginCode(identifier, code)),
        [settle],
    );

    const completeTwoFactor = useCallback(
        async (challengeToken: string, method: TwoFactorMethod, code: string) => {
            const response = await verifyTwoFactor(challengeToken, method, code);

            await finishLogin(response.token);
        },
        [finishLogin],
    );

    const logout = useCallback(async () => {
        // Stop syncing before the token goes, so nothing of this session
        // runs on into the next one.
        await syncManager.stopAndSettle();

        try {
            await apiLogout();
        } catch {
            // Best-effort — even if the network call fails, proceed to
            // clear local session state.
        }

        // The company's records leave the phone with the person — unless
        // some are still unsent: those stay, for this same person to send
        // when they sign in again (nobody else can sign in until then).
        if ((await countUnsent()) === 0) {
            await resetLocalData();
        }

        setCurrentToken(null);
        await clearStoredToken();
        await clearCachedProfile();
        clearInbox();
        await clearAppLock();
        setUser(null);
        setRole(null);
        setPermissions([]);
        setRoleConfirmed(false);
        setStatus('unauthenticated');
    }, [resetLocalData]);

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
        () => ({ status, user, role, permissions, can, roleConfirmed, login, loginWithCode, completeTwoFactor, logout, updateCachedUser, otherPersonsUnsent, discardOtherData, fingerprintSignIn, loginWithFingerprint, setFingerprintSignIn }),
        [status, user, role, permissions, can, roleConfirmed, login, loginWithCode, completeTwoFactor, logout, updateCachedUser, otherPersonsUnsent, discardOtherData, fingerprintSignIn, loginWithFingerprint, setFingerprintSignIn],
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
