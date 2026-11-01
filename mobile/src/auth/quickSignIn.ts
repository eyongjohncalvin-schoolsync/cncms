import * as LocalAuthentication from 'expo-local-authentication';
import * as SecureStore from 'expo-secure-store';

/**
 * Fingerprint sign-in (owner, 2026-11-01). The server's quick sign-in key
 * (App\Services\QuickSignInService) — it can only be traded for a normal
 * token, never call the API itself — kept in the phone's secure store and
 * only read after the phone's own fingerprint prompt passes. One person
 * per phone: whoever turned it on last. Unlike the session, it survives
 * "Log out" — that is the point of it.
 */
const KEY = 'cncms_quick_sign_in';
const OFFERED_PREFIX = 'cncms_quick_sign_in_offered_';
const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };

export interface QuickSignIn {
    /** The key's id on the server, to turn it off. */
    id: number;
    key: string;
    userUuid: string;
    /** Shown on the sign-in screen: "Sign in as …". */
    name: string;
}

export async function getQuickSignIn(): Promise<QuickSignIn | null> {
    try {
        const raw = await SecureStore.getItemAsync(KEY, OPTIONS);

        return raw === null ? null : (JSON.parse(raw) as QuickSignIn);
    } catch {
        return null;
    }
}

export async function saveQuickSignIn(quickSignIn: QuickSignIn): Promise<void> {
    await SecureStore.setItemAsync(KEY, JSON.stringify(quickSignIn), OPTIONS);
}

export async function clearQuickSignIn(): Promise<void> {
    await SecureStore.deleteItemAsync(KEY, OPTIONS);
}

/** The phone's own fingerprint (or face) prompt; false when cancelled or failed. */
export async function confirmFingerprint(promptMessage: string): Promise<boolean> {
    try {
        const result = await LocalAuthentication.authenticateAsync({
            promptMessage,
            cancelLabel: 'Use password',
            // The password is the fallback, not the phone's own screen lock.
            disableDeviceFallback: true,
        });

        return result.success;
    } catch {
        return false;
    }
}

/** Offered once per person after a password sign-in; Settings has the switch after that. */
export async function wasOffered(userUuid: string): Promise<boolean> {
    return (await SecureStore.getItemAsync(`${OFFERED_PREFIX}${userUuid}`, OPTIONS)) === '1';
}

export async function markOffered(userUuid: string): Promise<void> {
    await SecureStore.setItemAsync(`${OFFERED_PREFIX}${userUuid}`, '1', OPTIONS);
}
