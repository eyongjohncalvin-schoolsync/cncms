import { Platform } from 'react-native';

/**
 * API base URL resolution.
 *
 * The design doc (mobile-app-react-native.md) references 127.0.0.1:8000 as
 * the local dev URL, but the backend actually observed running in this
 * environment during development/verification was
 * `php artisan serve --port=8124` (127.0.0.1:8124) — see the phase-1 report
 * for how that was confirmed. EXPO_PUBLIC_API_BASE_URL overrides both.
 *
 * Android emulators can't reach the host machine via `127.0.0.1` (that
 * resolves to the emulator itself) — they need the special `10.0.2.2`
 * alias. A physical device on the same Wi-Fi needs the host's real LAN IP,
 * which can't be guessed here, so EXPO_PUBLIC_API_BASE_URL is the
 * expected override for that case.
 */
const DEFAULT_DEV_PORT = 8124;

function defaultBaseUrl(): string {
    const host = Platform.OS === 'android' ? '10.0.2.2' : '127.0.0.1';

    return `http://${host}:${DEFAULT_DEV_PORT}/api/v1`;
}

export const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? defaultBaseUrl();
