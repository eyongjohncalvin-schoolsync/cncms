import * as Crypto from 'expo-crypto';

/**
 * Client-generated local_uuid (UUID v4) per mobile-app-react-native.md §2 —
 * the server replaces this with a UUID v7 `server_uuid` on successful sync.
 * expo-crypto's randomUUID() is backed by the platform's real CSPRNG (not
 * Math.random), which matters since these values are also used as the
 * unique idempotency key the server dedupes pushes on.
 */
export function generateUuid(): string {
    return Crypto.randomUUID();
}
