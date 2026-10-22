/**
 * Pure helpers for the account-security screens (login challenge, email
 * code sign-in, two-factor setup). No React / React Native imports so they
 * stay unit-testable under node:test (src/utils/__tests__/twoFactor.test.ts).
 */

export type OneTimeCodeKind = 'totp' | 'email' | 'recovery';

/** POST /auth/login and /auth/login-code/verify answer with either shape. */
export interface ChallengeLike {
    two_factor_required?: boolean;
    challenge_token?: string;
}

export function isTwoFactorChallenge(response: object): response is ChallengeLike & { two_factor_required: true; challenge_token: string } {
    const candidate = response as ChallengeLike;

    return candidate.two_factor_required === true && typeof candidate.challenge_token === 'string' && candidate.challenge_token !== '';
}

/**
 * Cleans what the user typed/pasted: authenticator and email codes keep
 * digits only (max 6); recovery codes are upper-cased letters/digits with
 * the dash re-inserted after the 5th character (max "ABCDE-FGHJK").
 */
export function normalizeOneTimeCode(raw: string, kind: OneTimeCodeKind): string {
    if (kind === 'recovery') {
        const compact = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);

        return compact.length > 5 ? `${compact.slice(0, 5)}-${compact.slice(5)}` : compact;
    }

    return raw.replace(/\D/g, '').slice(0, 6);
}

/** True once the code has the full length for its kind. */
export function isCompleteOneTimeCode(code: string, kind: OneTimeCodeKind): boolean {
    if (kind === 'recovery') {
        return /^[A-Z0-9]{5}-?[A-Z0-9]{5}$/.test(code);
    }

    return /^\d{6}$/.test(code);
}

/** "ABCDEFGHIJKL" -> "ABCD EFGH IJKL", for reading a setup key aloud/typing it. */
export function formatSetupKey(secret: string): string {
    return secret.replace(/\s+/g, '').replace(/(.{4})(?=.)/g, '$1 ');
}

/**
 * A short label for a signed-in web session from its User-Agent, e.g.
 * "Chrome · Windows". Falls back to the first 40 characters.
 */
export function describeUserAgent(userAgent: string | null | undefined): string {
    if (!userAgent) {
        return 'Unknown browser';
    }

    const browser = /Edg\//.test(userAgent)
        ? 'Edge'
        : /Chrome\//.test(userAgent)
          ? 'Chrome'
          : /Firefox\//.test(userAgent)
            ? 'Firefox'
            : /Safari\//.test(userAgent)
              ? 'Safari'
              : null;
    const os = /Windows/.test(userAgent)
        ? 'Windows'
        : /Android/.test(userAgent)
          ? 'Android'
          : /iPhone|iPad/.test(userAgent)
            ? 'iOS'
            : /Mac OS/.test(userAgent)
              ? 'macOS'
              : /Linux/.test(userAgent)
                ? 'Linux'
                : null;

    const label = [browser, os].filter(Boolean).join(' · ');

    return label !== '' ? label : userAgent.slice(0, 40);
}
