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
 * Resolves the tenant for an authenticated API request and verifies the
 * authenticated user actually belongs to it, via the central
 * TenantUserIndex table (see its migration's docblock). Previously this
 * hard-coded a single tenant ("swecom") as documented tech debt; replaced
 * now that self-service workspace registration means more than one tenant
 * can exist and a user's tenant can no longer be assumed.
 *
 * A user belonging to more than one tenant (not yet possible via any UI
 * path — self-service registration creates exactly one) would resolve to
 * whichever index row is returned first; that's an acceptable simplification
 * until multi-tenant-membership-per-user becomes a real product need.
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

        // Win 1 (perf): resolution below is three central-DB lookups
        // (TenantUserIndex, Tenant, TenantUser) plus TenantContext::resolve()'s
        // own agent/zone query. The token-auth pipeline can re-enter this
        // middleware twice within a single request; when it does, the second
        // pass is pure waste — the first pass already ran the whole body
        // (approval/is_active gates included), initialized tenancy, bound
        // TenantContext, and left `tenant_user` on the Request. The Request
        // instance is per-HTTP-request, so this guard fires only on a genuine
        // same-request re-entry, never across requests; the tenancy()
        // check keeps it from short-circuiting if something ended tenancy
        // between the two passes. See ResolveTenantWeb for the twin.
        if ($request->attributes->has('tenant_user') && tenancy()->initialized) {
            return $next($request);
        }

        $indexEntry = TenantUserIndex::query()->where('user_id', $user->id)->first();

        if (! $indexEntry) {
            return response()->json([
                'message' => 'You do not have access to any tenant.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $tenant = Tenant::find($indexEntry->tenant_id);

        if (! $tenant) {
            return response()->json([
                'message' => 'Tenant not found.',
                'code' => 'NOT_FOUND',
            ], 500);
        }

        if (! $tenant->isApproved()) {
            return response()->json([
                'message' => 'This workspace is awaiting landlord approval.',
                'code' => 'WORKSPACE_PENDING',
            ], 403);
        }

        // A landlord can deactivate a tenant (is_active = false). Its users
        // keep valid credentials and a membership row, but must not reach
        // tenant data. `is_active` is a VirtualColumn defaulting to true, so
        // tenants that predate the flag are unaffected.
        if (! $tenant->is_active) {
            return response()->json([
                'message' => 'This workspace has been deactivated. Contact your administrator.',
                'code' => 'WORKSPACE_SUSPENDED',
            ], 403);
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

        $context = TenantContext::resolve($tenantUser);

        app()->instance(TenantContext::class, $context);

        $request->attributes->set('tenant_role', $context->role);
        $request->attributes->set('tenant_user', $tenantUser);

        return $next($request);
    }
}
