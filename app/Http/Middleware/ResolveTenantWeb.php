<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\BranchViewController;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Support\DemoReadOnly;
use App\Support\Impersonation;
use App\Support\MaintenanceMode;
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

        // Platform maintenance (App\Support\MaintenanceMode): hold everyone
        // but landlords on the countdown page until the window ends — a
        // landlord impersonating a user is still a landlord at the keyboard.
        if (MaintenanceMode::blocks($user) && ! Impersonation::active()) {
            return redirect()->route('maintenance');
        }

        // Win 1 (perf): see App\Http\Middleware\ResolveTenant for the full
        // rationale. Session-auth normally runs this middleware once per
        // request, but a re-dispatch within the same request would repeat
        // three central-DB lookups + TenantContext::resolve()'s query for
        // nothing. `tenant_user` on the Request is set only after this body
        // has fully run (approval/is_active gates included), and the Request
        // instance is per-HTTP-request, so this never masks a stale gate
        // across navigations.
        if ($request->attributes->has('tenant_user') && tenancy()->initialized) {
            return $next($request);
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

        // Free demo over and not yet activated: read-only (DemoReadOnly).
        if (DemoReadOnly::blocks($request, $tenant)) {
            return DemoReadOnly::webResponse($request);
        }

        tenancy()->initialize($tenant);

        $tenantUser = TenantUser::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        abort_if(! $tenantUser, 403, 'You do not have access to this tenant.');

        $context = TenantContext::resolve($tenantUser);

        // Branch switcher (branches v2): an unfenced user viewing one branch.
        $viewedBranchId = $request->session()->get(BranchViewController::SESSION_KEY);

        if ($context->branchId === null && is_int($viewedBranchId) && Branch::allMemoized()->has($viewedBranchId)) {
            $context = $context->viewingBranch($viewedBranchId);
        }

        app()->instance(TenantContext::class, $context);

        $request->attributes->set('tenant_role', $context->role);
        $request->attributes->set('tenant_user', $tenantUser);

        return $next($request);
    }
}
