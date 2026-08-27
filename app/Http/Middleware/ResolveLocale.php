<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active locale for a web request and calls App::setLocale()
 * once, before HandleInertiaRequests::share() reads app()->getLocale() for
 * the shared `locale` Inertia prop and before any FormRequest rules()/
 * messages() run (so lang/{locale}/validation.php resolves correctly). See
 * .ai/skills/cncms/cncms-context/references/language-support.md section 4
 * for the full design.
 *
 * Resolution order:
 *   1. session('locale') — a session/cookie override, primarily for
 *      guest/pre-auth routes (login/register) where there is no `users` row
 *      to read yet.
 *   2. auth()->user()->locale — the authenticated user's own saved
 *      preference (central `users` table), when set.
 *   3. Company::cached()->default_locale — the current tenant's default,
 *      only consulted when a tenant has actually been resolved for this
 *      request (i.e. App\Http\Middleware\ResolveTenantWeb has run). Follows
 *      the same app()->bound()-based check HandleInertiaRequests already
 *      uses for TenantContext, rather than a parallel resolution mechanism.
 *   4. config('app.locale') — the hard 'en' fallback.
 *
 * Registered in bootstrap/app.php immediately after ResolveTenantWeb in the
 * priority list, so tenancy (and therefore Company::cached()) is already
 * initialized by the time this runs on tenant-scoped routes; it still runs
 * on guest routes (no tenant bound yet), where only steps 1 and 4 apply.
 */
class ResolveLocale
{
    /**
     * @var list<string>
     */
    public const array SUPPORTED = ['en', 'fr'];

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $sessionLocale = $request->session()->get('locale');

        if (is_string($sessionLocale) && in_array($sessionLocale, self::SUPPORTED, true)) {
            return $sessionLocale;
        }

        $user = $request->user();

        if ($user && is_string($user->locale) && in_array($user->locale, self::SUPPORTED, true)) {
            return $user->locale;
        }

        if (tenancy()->initialized) {
            $company = Company::cached();

            if ($company && is_string($company->default_locale) && in_array($company->default_locale, self::SUPPORTED, true)) {
                return $company->default_locale;
            }
        }

        return (string) config('app.locale', 'en');
    }
}
