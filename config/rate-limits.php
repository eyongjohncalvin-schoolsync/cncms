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

    // Login brute-force protection, keyed by IP. Applies to BOTH the web
    // session login (POST /login) and the API token login
    // (POST /api/v1/auth/login).
    'login' => [
        'max_attempts' => (int) env('RATE_LIMIT_LOGIN_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('RATE_LIMIT_LOGIN_DECAY_MINUTES', 1),
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

];
