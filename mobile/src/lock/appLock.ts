import * as Crypto from 'expo-crypto';
import * as LocalAuthentication from 'expo-local-authentication';
import * as SecureStore from 'expo-secure-store';

/**
 * The app lock's storage (wave 6). The PIN never leaves the phone and is
 * kept only as a salted SHA-256 hash in the phone's secure store; the
 * wrong-attempt count is stored too, so closing and reopening the app
 * doesn't reset it. Everything here is wiped whenever the session ends
 * (AuthContext), so the next person to sign in on a shared phone sets
 * their own.
 */
const PIN_KEY = 'cncms_app_lock_pin';
const BIOMETRIC_KEY = 'cncms_app_lock_biometric';
const ATTEMPTS_KEY = 'cncms_app_lock_attempts';
const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };

interface StoredPin {
    salt: string;
    hash: string;
}

async function hash(pin: string, salt: string): Promise<string> {
    return Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, `${salt}:${pin}`);
}

export async function isLockEnabled(): Promise<boolean> {
    return (await SecureStore.getItemAsync(PIN_KEY, OPTIONS)) !== null;
}

export async function setPin(pin: string): Promise<void> {
    const salt = Crypto.randomUUID();
    const stored: StoredPin = { salt, hash: await hash(pin, salt) };

    await SecureStore.setItemAsync(PIN_KEY, JSON.stringify(stored), OPTIONS);
    await SecureStore.deleteItemAsync(ATTEMPTS_KEY, OPTIONS);
}

/** True and the count reset, or false and one more wrong attempt counted. */
export async function checkPin(pin: string): Promise<{ ok: boolean; failedAttempts: number }> {
    const raw = await SecureStore.getItemAsync(PIN_KEY, OPTIONS);

    if (raw === null) {
        return { ok: true, failedAttempts: 0 };
    }

    const stored = JSON.parse(raw) as StoredPin;

    if ((await hash(pin, stored.salt)) === stored.hash) {
        await SecureStore.deleteItemAsync(ATTEMPTS_KEY, OPTIONS);

        return { ok: true, failedAttempts: 0 };
    }

    const failedAttempts = Number((await SecureStore.getItemAsync(ATTEMPTS_KEY, OPTIONS)) ?? '0') + 1;
    await SecureStore.setItemAsync(ATTEMPTS_KEY, String(failedAttempts), OPTIONS);

    return { ok: false, failedAttempts };
}

export async function biometricEnabled(): Promise<boolean> {
    return (await SecureStore.getItemAsync(BIOMETRIC_KEY, OPTIONS)) === '1';
}

export async function setBiometric(enabled: boolean): Promise<void> {
    if (enabled) {
        await SecureStore.setItemAsync(BIOMETRIC_KEY, '1', OPTIONS);
    } else {
        await SecureStore.deleteItemAsync(BIOMETRIC_KEY, OPTIONS);
    }
}

/** The phone has a fingerprint (or face) sensor with one enrolled. */
export async function biometricAvailable(): Promise<boolean> {
    try {
        return (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync());
    } catch {
        return false;
    }
}

/** The phone's own fingerprint prompt; false when cancelled or failed. */
export async function unlockWithBiometric(): Promise<boolean> {
    try {
        const result = await LocalAuthentication.authenticateAsync({
            promptMessage: 'Unlock CNCMS',
            cancelLabel: 'Use PIN',
            // The PIN is the fallback, not the phone's own screen lock.
            disableDeviceFallback: true,
        });

        return result.success;
    } catch {
        return false;
    }
}

/** Removes the lock entirely — sign-out, or turned off in More → App lock. */
export async function clearAppLock(): Promise<void> {
    await Promise.all([PIN_KEY, BIOMETRIC_KEY, ATTEMPTS_KEY].map((key) => SecureStore.deleteItemAsync(key, OPTIONS)));
}
