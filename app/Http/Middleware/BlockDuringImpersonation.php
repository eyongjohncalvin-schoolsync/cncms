<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A landlord impersonating a workspace user (App\Support\Impersonation)
 * may look at "My account" but never change that person's credentials:
 * profile, email, password, two-factor or sessions. Applied to those write
 * routes as `not-impersonating`.
 */
class BlockDuringImpersonation
{
    public const string MESSAGE = 'Not available while impersonating: only the account owner can change their profile, email, password, two-factor or sessions.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Impersonation::active()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'IMPERSONATING'], 403);
        }

        return back(fallback: route('account.show'))->with('error', self::MESSAGE);
    }
}
