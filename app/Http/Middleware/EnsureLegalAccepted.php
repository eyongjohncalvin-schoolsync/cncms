<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\LegalDocumentService;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the landlord publishes Terms marked "must accept", the company's
 * super/admin is sent to the acceptance page (LegalAcceptanceController)
 * on their next page view, until someone in the company accepts. Other
 * staff are never stopped, nor is a landlord using "Log in as".
 *
 * Only page views are redirected: a form being submitted still goes
 * through, so nobody loses what they were typing.
 */
class EnsureLegalAccepted
{
    public function __construct(private readonly LegalDocumentService $legal) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('site.enforce_legal_acceptance') || ! $request->isMethod('GET') || $request->routeIs('legal.accept.*') || Impersonation::active() || ! tenancy()->initialized) {
            return $next($request);
        }

        $context = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;

        if ($context === null || ! ($context->isSuper() || $context->is('admin'))) {
            return $next($request);
        }

        if ($this->legal->termsAwaitingAcceptance((string) tenant('id')) !== null) {
            return redirect()->guest(route('legal.accept.show'));
        }

        return $next($request);
    }
}
