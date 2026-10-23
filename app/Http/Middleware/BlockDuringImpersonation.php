<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes a landlord impersonating a workspace user (App\Support\Impersonation)
 * must never make, applied to routes as `not-impersonating[:scope]`:
 *
 *   - `account` (default) — that person's credentials: profile, email,
 *     password, two-factor or sessions.
 *   - `money` — every money action (E9 of
 *     docs/plans/expenditures-and-spending-controls.md): payments and their
 *     verification, bons, the cash book / handovers / day close,
 *     installation charges, technician payouts, expenditures, budgets and
 *     the spending limits. Money is only ever moved by the real person.
 *
 * The API never impersonates, so it needs no such guard.
 */
class BlockDuringImpersonation
{
    public const string MESSAGE = 'Not available while impersonating: only the account owner can change their profile, email, password, two-factor or sessions.';

    public const string MONEY_MESSAGE = 'Not available while impersonating: money can only be recorded, approved or moved by the real person.';

    public function handle(Request $request, Closure $next, string $scope = 'account'): Response
    {
        if (! Impersonation::active()) {
            return $next($request);
        }

        $message = $scope === 'money' ? self::MONEY_MESSAGE : self::MESSAGE;

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'code' => 'IMPERSONATING'], 403);
        }

        return back(fallback: route($scope === 'money' ? 'dashboard' : 'account.show'))->with('error', $message);
    }
}
