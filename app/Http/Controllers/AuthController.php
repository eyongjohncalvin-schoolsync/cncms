<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsUsersIn;
use App\Models\LoginChallenge;
use App\Models\LoginFailure;
use App\Models\User;
use App\Services\LoginGuard;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Session-cookie login for the Inertia web admin panel. Separate from
 * Api\AuthController, which issues Sanctum bearer tokens for the mobile
 * agent app — the two frontends use different auth mechanisms by design
 * (see cncms-context SKILL.md "Frontend Architecture"). Web pages never
 * call /api/v1/* directly; web controllers call the same Services the API
 * controllers use and render Inertia responses instead of JSON.
 */
class AuthController extends Controller
{
    use LogsUsersIn;

    public function __construct(private readonly LoginGuard $loginGuard) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Password sign-in. The username field takes a username or an email.
     * A passive (deactivated) account is refused even with the right
     * password; with two-factor on, the user goes to the two-factor
     * challenge instead of being signed in (see LogsUsersIn).
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::findForLogin($credentials['username']);

        // Repeated wrong passwords block the account for a while
        // (App\Services\LoginGuard) — checked before the password, so a
        // guesser learns nothing while it lasts.
        if ($blockedUntil = $this->loginGuard->blockedUntil($user, $credentials['username'])) {
            $this->loginGuard->recordBlockedAttempt($user, $credentials['username'], LoginFailure::WEB, $request);

            throw ValidationException::withMessages(['username' => $this->loginGuard->blockedMessage($blockedUntil)]);
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $blockedUntil = $this->loginGuard->recordFailure($user, $credentials['username'], LoginFailure::WEB, $request);

            throw ValidationException::withMessages([
                'username' => $blockedUntil ? $this->loginGuard->blockedMessage($blockedUntil) : 'These credentials do not match our records.',
            ]);
        }

        $this->loginGuard->clear($user, $credentials['username']);

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'username' => 'This account has been deactivated. Contact your administrator.',
            ]);
        }

        return $this->proceedAfterFirstFactor($request, $user, LoginChallenge::FACTOR_PASSWORD);
    }

    public function destroy(Request $request): RedirectResponse
    {
        // "Log out" while impersonating ends the impersonation and returns
        // the landlord to their own account, rather than signing them out.
        if (Impersonation::active()) {
            Impersonation::stop();

            return redirect()->route('landlord.tenants.index')->with('success', 'Back to your own account.');
        }

        Auth::logout();

        // Belt-and-suspenders: session()->invalidate() below already
        // flushes ALL session data (Illuminate\Session\Store::invalidate()
        // calls flush() before migrating the ID), so this specific key is
        // already gone the instant invalidate() runs. Kept explicit anyway
        // so this guarantee survives a future refactor that reorders or
        // removes the invalidate() call without deleting this comment too.
        $request->session()->forget('url.intended');

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Must run after invalidate(): Inertia::clearHistory() works by
        // writing a flag into the session (inertia.clear_history — see
        // vendor/inertiajs/inertia-laravel/src/Support/SessionKey.php and
        // ResponseFactory::clearHistory()) that the *next* Inertia response
        // reads and forwards to the client as `clearHistory: true`,
        // instructing the browser to purge its cached history.state.
        // Writing it before invalidate() would just get wiped by the flush
        // invalidate() performs.
        Inertia::clearHistory();

        return redirect()->route('login');
    }
}
