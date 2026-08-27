<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the central/platform-level "landlord" area (routes/web/landlord.php),
 * where a small number of ShalomTech staff manage the `tenants` table itself
 * (onboarding future LCO clients) — distinct from the tenant-scoped admin
 * panel each tenant's own staff use under routes/web/*.php.
 *
 * Landlord access is `users.is_landlord` — a platform-wide authority flag
 * on the CENTRAL user record, with zero dependency on tenant context.
 * This replaces an earlier version of this middleware that checked
 * "role=super within the swecom tenant specifically" — wrong on two
 * counts: (1) `tenant_users.role` is a per-tenant attribute by design
 * (stancl/tenancy's own docs model a synced `role` column as deliberately
 * NOT central), so it can never correctly answer a platform-wide
 * question, and (2) it meant a brand new self-service tenant's own
 * `super` user was only safe from landlord powers by coincidence (wrong
 * tenant id), not because they were never actually granted anything.
 * `is_landlord` fixes both: it's an explicit central grant, checked
 * identically no matter which tenant (if any) the request resolves to.
 *
 * Landlord pages never initialize tenancy at all now — there's nothing
 * tenant-scoped left to look up for this check, so the init/end
 * bracketing the previous version needed is gone.
 */
class EnsureLandlord
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(! $user, 403);
        abort_if(! $user->is_landlord, 403, 'You do not have access to the landlord area.');

        return $next($request);
    }
}
