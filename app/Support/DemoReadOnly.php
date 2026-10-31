<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A workspace whose free demo has ended (Tenant::isReadOnly()) can still
 * sign in, look at everything and export it, but saves nothing new until a
 * landlord activates it. Enforced by ResolveTenantWeb and ResolveTenant:
 * every request that is not a read is refused, except signing out, the
 * user's own account and security, accepting the Terms, signing the
 * service contract (the step towards activation), and the few
 * session-only switches below. Nothing is ever deleted.
 *
 * The mobile app treats the refusal like any failed sync: queued work stays
 * on the phone and syncs once the workspace is activated.
 */
class DemoReadOnly
{
    public const string CODE = 'DEMO_ENDED';

    /** Writes that are still allowed (Request::is() patterns). */
    private const array ALLOWED = [
        'logout',
        'account', 'account/*',
        'legal/accept',
        'settings/contract/sign',
        'impersonation/stop',
        'branch-view',
        'settings/locale',
        'notifications/*',
        '*/preview', '*-preview',
        'api/v1/auth/*',
        'api/v1/devices/push-token',
        'api/v1/notifications/*',
    ];

    public static function blocks(Request $request, Tenant $tenant): bool
    {
        return ! $request->isMethodSafe()
            && $tenant->isReadOnly()
            && ! $request->is(...self::ALLOWED);
    }

    public static function message(): string
    {
        return __('site.demo_read_only', [
            'company' => config('site.company'),
            'phone' => config('site.phone'),
        ]);
    }

    public static function webResponse(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return self::apiResponse();
        }

        return back()->with('error', self::message());
    }

    public static function apiResponse(): JsonResponse
    {
        return response()->json(['message' => self::message(), 'code' => self::CODE], 403);
    }
}
