<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\LandlordActivity;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a landlord impersonates a workspace user (App\Support\
 * Impersonation), records every write request they make — method, path and
 * outcome — in the landlord activity log, attributed to the landlord.
 * Reads are not logged; the start/stop entries bracket the session.
 */
class RecordImpersonatedActions
{
    /** Start/stop/logout write their own impersonation.started/stopped entries. */
    private const array UNLOGGED_ROUTES = ['landlord.impersonate', 'impersonation.stop', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            ! $request->isMethodSafe()
            && Impersonation::active()
            && ! in_array($request->route()?->getName(), self::UNLOGGED_ROUTES, true)
        ) {
            $user = $request->user();

            LandlordActivity::record(
                'impersonation.action',
                "As {$user?->name}: {$request->method()} /{$request->path()}",
                'user',
                $user?->id,
                [
                    'method' => $request->method(),
                    'path' => '/'.$request->path(),
                    'route' => $request->route()?->getName(),
                    'status' => $response->getStatusCode(),
                ],
            );
        }

        return $response;
    }
}
