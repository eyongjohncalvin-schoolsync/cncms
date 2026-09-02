<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session-auth equivalent of App\Http\Middleware\ResolveTenant — see that
 * class's doc comment for the TenantUserIndex-based resolution this now
 * uses (replacing the previous hard-coded single-tenant lookup, no longer
 * viable once self-service workspace registration can create more than
 * one tenant).
 */
class ResolveTenantWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $indexEntry = TenantUserIndex::query()->where('user_id', $user->id)->first();

        abort_if(! $indexEntry, 403, 'You do not have access to any tenant yet.');

        $tenant = Tenant::find($indexEntry->tenant_id);

        abort_if(! $tenant, 500, 'Tenant not found.');

        // Awaiting approval OR deactivated by a landlord — either way this
        // user gets no further than the holding page. `is_active` is a
        // VirtualColumn defaulting to true (Tenant::isActive), so a tenant
        // that predates the flag is unaffected.
        if (! $tenant->isApproved() || ! $tenant->is_active) {
            return redirect()->route('workspace.pending');
        }

        tenancy()->initialize($tenant);

        $tenantUser = TenantUser::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        abort_if(! $tenantUser, 403, 'You do not have access to this tenant.');

        $context = TenantContext::resolve($tenantUser);

        app()->instance(TenantContext::class, $context);

        $request->attributes->set('tenant_role', $context->role);
        $request->attributes->set('tenant_user', $tenantUser);

        return $next($request);
    }
}
