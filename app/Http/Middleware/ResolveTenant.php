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
 * Resolves the tenant for an authenticated API request and verifies the
 * authenticated user actually belongs to it.
 *
 * SCOPE NOTE: CNCMS currently has exactly one tenant ("swecom"). This
 * middleware hard-codes that tenant rather than doing a general
 * "which tenant does this user belong to" lookup, because there is no
 * central users->tenant lookup table yet, and scanning every tenant
 * schema's `tenant_users` table for every request would be impractical
 * once there's more than a handful of tenants. Once a second tenant is
 * provisioned, this needs to be replaced with a real central lookup
 * (e.g. a central `tenant_user_index` table keyed by user_id, populated
 * whenever a `tenant_users` row is created/deleted) so the correct
 * tenant can be resolved without hard-coding a slug here.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $tenant = Tenant::find('swecom');

        if (! $tenant) {
            return response()->json([
                'message' => 'Tenant not found.',
                'code' => 'NOT_FOUND',
            ], 500);
        }

        tenancy()->initialize($tenant);

        $tenantUser = TenantUser::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $tenantUser) {
            return response()->json([
                'message' => 'You do not have access to this tenant.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $context = new TenantContext($tenantUser, $tenantUser->role);

        app()->instance(TenantContext::class, $context);

        $request->attributes->set('tenant_role', $context->role);
        $request->attributes->set('tenant_user', $tenantUser);

        return $next($request);
    }
}
