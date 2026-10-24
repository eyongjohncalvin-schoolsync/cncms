<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentReceipt;
use App\Models\PersonalAccessToken;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use App\Policies\PaymentReceiptPolicy;
use App\Policies\ReportPolicy;
use App\Policies\RolePolicy;
use App\Policies\SalaryPolicy;
use App\Services\FreeMonthGrantService;
use App\Support\BranchLookup;
use App\Support\BranchPrices;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        // One instance per request/job: the add-customer flow defers the
        // manuscript recalculation that grant changes trigger until the
        // whole save is done (FreeMonthGrantService::deferRecalculation()).
        $this->app->scoped(FreeMonthGrantService::class);
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

        // A passive (deactivated) user's token stops authenticating at once,
        // even one that survived the token purge in
        // AccountSecurityService::deactivate() — the API counterpart of the
        // web EnsureAccountIsActive middleware.
        Sanctum::authenticateAccessTokensUsing(
            static fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && (! $token->tokenable instanceof User || $token->tokenable->isActive()),
        );

        // App\Models\Report is not a real Eloquent model (no `reports`
        // table) — see its class doc for why this is registered explicitly
        // rather than relying on Laravel's naming-convention auto-discovery.
        Gate::policy(Report::class, ReportPolicy::class);

        // RBAC v2 Wave 3: RolePolicy takes a Role model argument on
        // update()/delete() (to block the is_super / is_system rows), so it
        // is registered explicitly alongside ReportPolicy rather than left
        // to naming-convention discovery — keeps all policy wiring in one
        // visible place.
        Gate::policy(Role::class, RolePolicy::class);

        // Payment receipts (Wave 2 of payment-receipts-and-whatsapp.md).
        // PaymentReceiptPolicy::view() takes a PaymentReceipt argument, so it
        // is registered here explicitly alongside the others rather than left
        // to naming-convention discovery — see PaymentReceiptPolicy's docblock.
        Gate::policy(PaymentReceipt::class, PaymentReceiptPolicy::class);

        // Branches v2 D8: salaries are not one model's policy — named gates.
        Gate::define('salaries.setFor', [SalaryPolicy::class, 'setFor']);
        Gate::define('salaries.manageScale', [SalaryPolicy::class, 'manageScale']);

        // Win 2 (perf): App\Models\Company::cached()'s per-request memo is
        // keyed by tenant id, but a long-lived worker that hops tenants (or
        // the test suite, which re-initializes tenancy every method) must
        // still start each tenant with a clean slate rather than risk a
        // key colliding after a reactivation/re-provision. Flushing on both
        // tenancy transitions bounds any staleness to a single job/test.
        Event::listen([TenancyInitialized::class, TenancyEnded::class], static fn () => Company::flushMemo());

        // Branches v2: parent → branch memo used to stamp branch_id on new rows.
        // A zone can change branch (its customers and agents follow it by
        // cascade), so a zone moving branch, and any tenant switch, starts it afresh.
        Event::listen([TenancyInitialized::class, TenancyEnded::class], static fn () => [BranchLookup::flush(), Branch::flushMemo(), BranchPrices::flush()]);
        Zone::updated(static function (Zone $zone): void {
            if ($zone->wasChanged('branch_id')) {
                BranchLookup::flush();
            }
        });

        $this->preventLazyLoading();

        $this->configureRateLimiting();
    }

    /**
     * N+1 guard (docs/plans/branches-v2.md §6): a lazy-loaded relation fails
     * the test suite, is only logged locally (the local app runs on real
     * data and must keep working), and is ignored in production.
     */
    private function preventLazyLoading(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        if (! $this->app->runningUnitTests()) {
            Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
                Log::warning('Lazy loading ['.$relation.'] on ['.$model::class.'].');
            });
        }
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
        // (POST /api/v1/auth/login). Keyed by account + IP: field agents on
        // MTN/Orange data share a handful of carrier IPs, so a per-IP-only
        // limit let one agent's typos lock out everyone in town. A looser
        // per-IP ceiling still stops one source spraying many accounts.
        RateLimiter::for('login', function (Request $request): array {
            $account = mb_strtolower(trim((string) ($request->input('email') ?? $request->input('username') ?? $request->input('login') ?? '')));

            return [
                Limit::perMinutes(config('rate-limits.login.decay_minutes'), config('rate-limits.login.max_attempts'))->by('account:'.$account.'|'.$request->ip()),
                Limit::perMinutes(config('rate-limits.login.decay_minutes'), config('rate-limits.login.ip_max_attempts'))->by('ip:'.$request->ip()),
            ];
        });

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

        // Public signed receipt-PDF share link. Always keyed by IP — this
        // route is deliberately unauthenticated (a WhatsApp recipient with
        // no account), so there is never a user id to key by.
        RateLimiter::for('receipt-share', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.receipt-share.decay_minutes'),
            config('rate-limits.receipt-share.max_attempts'),
        )->by($request->ip()));

        // General ceiling for the authenticated tenant-scoped web/Inertia
        // panel. Keyed by authenticated user id (falls back to IP
        // defensively). Deliberately generous — see config/rate-limits.php.
        RateLimiter::for('web', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.web.decay_minutes'),
            config('rate-limits.web.max_attempts'),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Sending a one-time email code. Keyed by the account the code is
        // for — the signed-in user, the pending login challenge, or the
        // typed username/email — plus IP and route, so a busy office behind
        // one IP isn't throttled as a whole.
        RateLimiter::for('security-code-send', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.security-code-send.decay_minutes'),
            config('rate-limits.security-code-send.max_attempts'),
        )->by(implode('|', [
            $request->route()?->getName() ?? $request->path(),
            $request->user()?->getAuthIdentifier()
                ?? sha1((string) ($request->input('challenge_token')
                    ?? ($request->hasSession() ? $request->session()->get('login.challenge_token') : null)
                    ?? '')),
            mb_strtolower(trim((string) $request->input('identifier'))),
            $request->ip(),
        ])));

        // Submitting a one-time code. Keyed by IP (plus the user when signed
        // in, for the new-email confirmation).
        RateLimiter::for('security-code-verify', fn (Request $request) => Limit::perMinutes(
            config('rate-limits.security-code-verify.decay_minutes'),
            config('rate-limits.security-code-verify.max_attempts'),
        )->by(($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip()));
    }
}
