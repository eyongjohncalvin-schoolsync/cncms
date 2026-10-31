<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Rate Limiting
|--------------------------------------------------------------------------
|
| Named limiter definitions consumed by App\Providers\AppServiceProvider's
| RateLimiter::for(...) registrations and applied via `throttle:{name}`
| middleware across routes/api.php, routes/api/*.php, routes/web.php, and
| routes/web/*.php. Documented targets: see
| .ai/skills/cncms/cncms-context/references/api-spec.md section 11.
|
| Every limit is env()-overridable so it can be tuned per environment
| without a code change/deploy — flagged as "very vital" by the project
| owner, so this file is the single source of truth for the numbers rather
| than magic numbers scattered across the provider.
|
*/

return [

    // Login brute-force protection. Applies to BOTH the web session login
    // (POST /login) and the API token login (POST /api/v1/auth/login).
    // max_attempts is per account + IP; ip_max_attempts is the ceiling for
    // one IP across all accounts (field agents share carrier IPs on mobile data).
    'login' => [
        'max_attempts' => (int) env('RATE_LIMIT_LOGIN_MAX_ATTEMPTS', 5),
        'ip_max_attempts' => (int) env('RATE_LIMIT_LOGIN_IP_MAX_ATTEMPTS', 50),
        'decay_minutes' => (int) env('RATE_LIMIT_LOGIN_DECAY_MINUTES', 1),
    ],

    // Account blocks on top of the per-minute limit above
    // (App\Services\LoginGuard): `block_after` wrong passwords for one
    // account within `window_minutes` block its password sign-in from every
    // device for `block_minutes`; another wrong password after that, still
    // within the window, blocks it for `long_block_minutes`. Never
    // permanent; email-code sign-in still works. `ip_alert_accounts`: one IP
    // failing on this many different accounts in the window alerts the
    // landlords.
    'login_block' => [
        'window_minutes' => 60,
        'block_after' => 10,
        'block_minutes' => 15,
        'long_block_minutes' => 60,
        // Device block: `device_block_after` wrong passwords from one IP
        // address within the window, whatever usernames were typed, block
        // password sign-in from that address for `device_block_minutes`.
        // High on purpose: agents on mobile data share carrier addresses.
        // LOGIN_TRUSTED_IPS (comma-separated, e.g. the office) are never
        // device-blocked.
        'device_block_after' => (int) env('LOGIN_DEVICE_BLOCK_AFTER', 30),
        'device_block_minutes' => (int) env('LOGIN_DEVICE_BLOCK_MINUTES', 60),
        'trusted_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOGIN_TRUSTED_IPS', ''))))),
        'ip_alert_accounts' => 10,
    ],

    // Self-service registration / workspace creation, keyed by IP. Stricter
    // and longer-windowed than login — provisioning a workspace creates a
    // real Postgres schema, so this must not be automatable.
    'registration' => [
        'max_attempts' => (int) env('RATE_LIMIT_REGISTRATION_MAX_ATTEMPTS', 3),
        'decay_minutes' => (int) env('RATE_LIMIT_REGISTRATION_DECAY_MINUTES', 60),
    ],

    // Offline sync push/pull, keyed by authenticated user (falls back to
    // IP). api-spec.md documents this "per device", but device_id is only
    // present on the sync/push request body, not on pull/status/upload
    // -receipt, so the user id is the consistent key across every sync
    // endpoint (in practice one authenticated agent == one device).
    'sync' => [
        'max_attempts' => (int) env('RATE_LIMIT_SYNC_MAX_ATTEMPTS', 60),
        'decay_minutes' => (int) env('RATE_LIMIT_SYNC_DECAY_MINUTES', 1),
    ],

    // Default limiter for all authenticated /api/v1/* traffic not covered
    // by a more specific limiter below. Keyed by authenticated user
    // (falls back to IP defensively, though these routes require
    // auth:sanctum).
    'api' => [
        'max_attempts' => (int) env('RATE_LIMIT_API_MAX_ATTEMPTS', 120),
        'decay_minutes' => (int) env('RATE_LIMIT_API_DECAY_MINUTES', 1),
    ],

    // PDF/ZIP export endpoints (manuscript export, bill print — API and
    // web), keyed by authenticated user. These are expensive to generate,
    // hence the much lower ceiling than standard CRUD.
    'exports' => [
        'max_attempts' => (int) env('RATE_LIMIT_EXPORTS_MAX_ATTEMPTS', 10),
        'decay_minutes' => (int) env('RATE_LIMIT_EXPORTS_DECAY_MINUTES', 1),
    ],

    // Public, signed, unauthenticated receipt-PDF share link
    // (payment-receipts/{uuid}/pdf/shared — the WhatsApp-shareable link, see
    // payment-receipts-and-whatsapp.md). Keyed by IP since there is no
    // authenticated user. Low ceiling: a valid signed link is handed to one
    // customer at a time, so anything hammering this is abuse.
    'receipt-share' => [
        'max_attempts' => (int) env('RATE_LIMIT_RECEIPT_SHARE_MAX_ATTEMPTS', 30),
        'decay_minutes' => (int) env('RATE_LIMIT_RECEIPT_SHARE_DECAY_MINUTES', 1),
    ],

    // Audit log query endpoints (API and web), keyed by authenticated user.
    'audit' => [
        'max_attempts' => (int) env('RATE_LIMIT_AUDIT_MAX_ATTEMPTS', 30),
        'decay_minutes' => (int) env('RATE_LIMIT_AUDIT_DECAY_MINUTES', 1),
    ],

    // General ceiling for the authenticated tenant-scoped web/Inertia panel
    // (routes/web.php's ['auth', 'tenant.web'] group), keyed by
    // authenticated user. Not part of the original api-spec.md doc — the
    // web panel is equally exposed to a compromised/scripted session
    // hammering pages, so it gets a generous-but-real ceiling. Deliberately
    // high so normal fast clicking/pagination and busy test suites never
    // trip it.
    'web' => [
        'max_attempts' => (int) env('RATE_LIMIT_WEB_MAX_ATTEMPTS', 300),
        'decay_minutes' => (int) env('RATE_LIMIT_WEB_DECAY_MINUTES', 1),
    ],

    // Sending a one-time email code (sign-in code, two-factor fallback code,
    // new-email confirmation code), keyed by the target account (identifier,
    // challenge or signed-in user) plus IP. Stops inbox flooding; the generic
    // response never says whether an account exists.
    'security-code-send' => [
        'max_attempts' => (int) env('RATE_LIMIT_SECURITY_CODE_SEND_MAX_ATTEMPTS', 3),
        'decay_minutes' => (int) env('RATE_LIMIT_SECURITY_CODE_SEND_DECAY_MINUTES', 10),
    ],

    // Submitting a one-time code (sign-in code, two-factor challenge,
    // new-email confirmation), keyed by IP. Each code and challenge also
    // dies after 5 wrong guesses on its own (SecurityCodeService,
    // LoginChallengeService), so this is the outer brute-force ceiling.
    'security-code-verify' => [
        'max_attempts' => (int) env('RATE_LIMIT_SECURITY_CODE_VERIFY_MAX_ATTEMPTS', 10),
        'decay_minutes' => (int) env('RATE_LIMIT_SECURITY_CODE_VERIFY_DECAY_MINUTES', 1),
    ],

];
