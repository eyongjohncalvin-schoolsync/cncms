<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantUserIndex;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Holding page for a user whose workspace (Tenant) has
 * registration_status = 'pending'/'rejected' — see
 * .ai/skills/cncms/cncms-context/references/self-service-onboarding.md.
 * Deliberately NOT behind ['auth', 'tenant.web'] (ResolveTenantWeb is what
 * redirects here in the first place; routing this page through the same
 * middleware would loop).
 */
class WorkspaceController extends Controller
{
    public function pending(Request $request): Response
    {
        $entry = TenantUserIndex::query()->where('user_id', $request->user()->id)->first();
        $tenant = $entry ? Tenant::find($entry->tenant_id) : null;

        // No index entry yet = App\Jobs\FinalizeWorkspaceProvisioning hasn't
        // run — the tenant schema is still being built on the queue. The
        // page polls this endpoint and advances once it flips.
        return Inertia::render('Workspace/Pending', [
            'provisioning' => $entry === null,
            'status' => $tenant?->registration_status ?? 'pending',
            'workspace_name' => $tenant?->name,
        ]);
    }
}
