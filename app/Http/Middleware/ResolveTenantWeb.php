<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session-auth equivalent of App\Http\Middleware\ResolveTenant, for the
 * Inertia web panel. Same single-tenant ("swecom") scope note applies —
 * see that class's doc comment. Kept as a separate, small middleware
 * rather than sharing code with the API version because the failure
 * modes differ: this one redirects/aborts for a browser session instead
 * of returning a JSON error body.
 */
class ResolveTenantWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $tenant = Tenant::find('swecom');

        abort_if(! $tenant, 500, 'Tenant not found.');

        tenancy()->initialize($tenant);

        $tenantUser = TenantUser::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        abort_if(! $tenantUser, 403, 'You do not have access to this tenant.');

        $context = new TenantContext($tenantUser, $tenantUser->role);

        app()->instance(TenantContext::class, $context);

        $request->attributes->set('tenant_role', $context->role);
        $request->attributes->set('tenant_user', $tenantUser);

        return $next($request);
    }
}
