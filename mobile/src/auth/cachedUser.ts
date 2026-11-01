import * as SecureStore from 'expo-secure-store';

/**
 * Where the signed-in person's profile is cached (AuthContext writes it).
 * Its own module so non-React code (src/fieldReceipts, SyncManager) can
 * read the user without importing AuthContext — which itself controls
 * SyncManager — and so creating an import cycle.
 */
export const PROFILE_KEY = 'cncms_auth_profile';
export const PROFILE_STORE_OPTIONS: SecureStore.SecureStoreOptions = {
    keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

/**
 * The signed-in user's uuid from the cached session profile, for code
 * that must key per-user data — a phone's receipt numbers belong to the
 * collector they were issued to, not to whoever signs in next. Null when
 * nobody is signed in.
 */
export async function getCachedUserUuid(): Promise<string | null> {
    const raw = await SecureStore.getItemAsync(PROFILE_KEY, PROFILE_STORE_OPTIONS);

    return raw ? ((JSON.parse(raw) as { user?: { uuid?: string } }).user?.uuid ?? null) : null;
}
