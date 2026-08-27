/**
 * In-memory mirror of the current Sanctum token, so the axios request
 * interceptor (src/api/client.ts) can attach it synchronously on every
 * request without an async SecureStore read per call. The source of truth
 * for persistence is still SecureStore (see tokenStorage.ts) — this is
 * purely a read-fast cache kept in sync by AuthContext.
 */
let currentToken: string | null = null;

export function getCurrentToken(): string | null {
    return currentToken;
}

export function setCurrentToken(token: string | null): void {
    currentToken = token;
}
