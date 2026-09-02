<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Get the Agent App" page (/agent-app). CNCMS's React Native field app
 * (mobile/) isn't published to the Play Store / App Store — agents install
 * a build directly. This page hands them the current Android build link,
 * a QR to open it on the phone (rendered client-side), and the install
 * steps. All distribution config lives in config/agent-app.php (env-driven),
 * so pointing agents at a new build is one env var, no deploy.
 *
 * Gated to the app's actual audience: agent (primary user), manager
 * (supervisory view), plus admin/super for oversight — same role set as
 * Reports. `worker` is excluded, matching how the nav link is hidden
 * client-side in resources/tsx/components/shared/AppNav.tsx.
 */
class AgentAppController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request): Response
    {
        // RBAC v2: the app's audience (super/admin/manager/agent) is exactly
        // the `manuscripts.view` role set, so this reuses that permission
        // rather than a hardcoded role list. Wave 4 (mobile) may split this
        // out into a dedicated `mobile.sync` permission if needed.
        abort_unless($this->context->can('manuscripts.view'), 403);

        return Inertia::render('AgentApp/Index', [
            'android_url' => config('agent-app.android_url'),
            'ios_url' => config('agent-app.ios_url'),
            'version' => config('agent-app.version'),
            'updated_on' => config('agent-app.updated_on'),
            'android_min' => config('agent-app.android_min'),
        ]);
    }
}
