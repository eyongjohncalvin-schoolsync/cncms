<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an already-open web session whose user has since become passive
 * (deactivated). Deactivating normally deletes the person's sessions at once
 * (AccountSecurityService::deactivate()); this is the guard for any session
 * that slipped through, checked on every web request. The session is ended
 * and the browser sent to the login page.
 *
 * While a landlord is impersonating a user who became passive, only the
 * impersonation ends: the landlord is switched back to their own account.
 *
 * The API counterpart is the Sanctum token check registered in
 * AppServiceProvider (a passive user's token no longer authenticates).
 */
class EnsureAccountIsActive
{
    public const string MESSAGE = 'This account has been deactivated. Contact your administrator.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || $user->isActive()) {
            return $next($request);
        }

        if (Impersonation::active()) {
            Impersonation::stop();

            return redirect()->route('landlord.users.index')->with('error', "{$user->name}'s account was deactivated, so the impersonation ended.");
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'ACCOUNT_DEACTIVATED'], 401);
        }

        return redirect()->route('login')->withErrors(['username' => self::MESSAGE]);
    }
}
