import * as SecureStore from 'expo-secure-store';

/**
 * Sanctum Bearer token storage. WHEN_UNLOCKED_THIS_DEVICE_ONLY per
 * mobile-app-react-native.md §7 — this specifically prevents the token
 * from surviving into a cloud backup restored on a different device
 * (iCloud/Android cloud backup), which the default keychain accessibility
 * class would otherwise allow.
 */
const TOKEN_KEY = 'cncms_auth_token';

const SECURE_STORE_OPTIONS: SecureStore.SecureStoreOptions = {
    keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

export async function getStoredToken(): Promise<string | null> {
    return SecureStore.getItemAsync(TOKEN_KEY, SECURE_STORE_OPTIONS);
}

export async function setStoredToken(token: string): Promise<void> {
    await SecureStore.setItemAsync(TOKEN_KEY, token, SECURE_STORE_OPTIONS);
}

export async function clearStoredToken(): Promise<void> {
    await SecureStore.deleteItemAsync(TOKEN_KEY, SECURE_STORE_OPTIONS);
}
