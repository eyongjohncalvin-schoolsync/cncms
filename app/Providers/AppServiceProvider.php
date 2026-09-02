<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\PersonalAccessToken;
use App\Models\Report;
use App\Policies\ReportPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // personal_access_tokens is a central-only table (see
        // App\Models\PersonalAccessToken for the full reasoning) —
        // point Sanctum at the connection-pinned model.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // App\Models\Report is not a real Eloquent model (no `reports`
        // table) — see its class doc for why this is registered explicitly
        // rather than relying on Laravel's naming-convention auto-discovery.
        Gate::policy(Report::class, ReportPolicy::class);

        // Win 2 (perf): App\Models\Company::cached()'s per-request memo is
        // keyed by tenant id, but a long-lived worker that hops tenants (or
        // the test suite, which re-initializes tenancy every method) must
        // still start each tenant with a clean slate rather than risk a
        // key colliding after a reactivation/re-provision. Flushing on both
        // tenancy transitions bounds any staleness to a single job/test.
        Event::listen([TenancyInitialized::class, TenancyEnded::class], static fn () => Company::flushMemo());

        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters applied via `throttle:{name}` middleware across
     * routes/api.php, routes/api/*.php, routes/web.php, and
     * routes/web/*.php. Limits/windows live in config/rate-limits.php
     * (env()-tunable per environment) rather than as magic numbers here —
     * see api-spec.md section 11 for the documented API targets this
     * mirrors, and config/rate-limits.php's doc block for the web-side
     * additions.
     */
    private function configureRateLimiting(): void
    {
        // Login brute-force protection — shared by both the web session
        // login (POST /login) and the API token login
        // (POST /api/v1/auth/login). Keyed by IP since there is no
        // authenticated user yet at the point of a login attempt.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.login.decay_minutes'),
            config('rate-limits.login.max_attempts'),
        )->by($request->ip()));

        // Self-service registration / workspace creation / Google OAuth
        // callback. Keyed by IP — provisioning a tenant schema is
        // expensive and must not be automatable.
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.registration.decay_minutes'),
            config('rate-limits.registration.max_attempts'),
        )->by($request->ip()));

        // Offline sync push/pull/status/upload-receipt. Keyed by
        // authenticated user id (falls back to IP defensively — these
        // routes require auth:sanctum so a null user should not occur).
        RateLimiter::for('sync', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.sync.decay_minutes'),
            config('rate-limits.sync.max_attempts'),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Default limiter for standard authenticated /api/v1/* CRUD
        // traffic. Keyed by authenticated user id (falls back to IP
        // defensively).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.api.decay_minutes'),
            config('rate-limits.api.max_attempts'),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // PDF/ZIP export endpoints (manuscript export, bill print — API
        // and web). Keyed by authenticated user id (falls back to IP
        // defensively).
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.exports.decay_minutes'),
            config('rate-limits.exports.max_attempts'),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Audit log query endpoints (API and web). Keyed by authenticated
        // user id (falls back to IP defensively).
        RateLimiter::for('audit', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.audit.decay_minutes'),
            config('rate-limits.audit.max_attempts'),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // General ceiling for the authenticated tenant-scoped web/Inertia
        // panel. Keyed by authenticated user id (falls back to IP
        // defensively). Deliberately generous — see config/rate-limits.php.
        RateLimiter::for('web', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.web.decay_minutes'),
            config('rate-limits.web.max_attempts'),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
