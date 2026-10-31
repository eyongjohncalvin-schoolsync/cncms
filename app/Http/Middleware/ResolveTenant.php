<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Support\BusinessTimezone;
use App\Support\DemoReadOnly;
use App\Support\MaintenanceMode;
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

        // Platform maintenance (App\Support\MaintenanceMode). The mobile app
        // treats this like being offline: work queues locally and syncs
        // once the window ends.
        if ($window = MaintenanceMode::blocks($user)) {
            return response()->json([
                'message' => 'The system is under maintenance until '
                    .$window->ends_at->copy()->setTimezone(BusinessTimezone::WAT)->format('d M H:i')
                    .' (Cameroon time). Please try again after that.',
                'code' => 'MAINTENANCE',
                'maintenance' => $window->toNotice(),
            ], 503, ['Retry-After' => (string) max(60, (int) now()->diffInSeconds($window->ends_at))]);
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

        // Free demo over and not yet activated: read-only (DemoReadOnly).
        if (DemoReadOnly::blocks($request, $tenant)) {
            return DemoReadOnly::apiResponse();
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

        $response = $next($request);

        // Advance notice for the mobile app's maintenance banner: every
        // tenant API response carries the scheduled (or, for a landlord,
        // running) window.
        if ($window = MaintenanceMode::current()) {
            $response->headers->set('X-Maintenance-Starts-At', $window->starts_at->toIso8601String());
            $response->headers->set('X-Maintenance-Ends-At', $window->ends_at->toIso8601String());
        }

        // The mobile app's "new version available" banner: the current
        // build (config/agent-app.php) and where to get it. Read from config,
        // so no query — two short headers on every response.
        if (($downloadUrl = config('agent-app.android_url')) !== null && $downloadUrl !== '') {
            $response->headers->set('X-App-Latest-Version', (string) config('agent-app.version'));
            $response->headers->set('X-App-Download-Url', (string) $downloadUrl);
        }

        return $response;
    }
}
