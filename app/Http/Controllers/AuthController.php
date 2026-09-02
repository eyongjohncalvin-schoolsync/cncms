<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TenantUserIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $field = str_contains($credentials['username'], '@') ? 'email' : 'username';

        // No "remember me" checkbox exists on Auth/Login.tsx, so there is no
        // user intent to honor here — attempt() defaults to session-only
        // auth (no persistent recaller cookie) when the second argument is
        // omitted. Previously hardcoded to `true`, which silently issued a
        // ~13-month persistent auth cookie on every login regardless of
        // whether the user ever asked for that.
        if (! Auth::attempt([$field => $credentials['username'], 'password' => $credentials['password']])) {
            throw ValidationException::withMessages([
                'username' => 'These credentials do not match our records.',
            ]);
        }

        // Auth::attempt() already rotates the session ID with the old
        // session's storage row destroyed (SessionGuard::updateSession()
        // calls $session->regenerate(true) internally) before this line
        // ever runs. This explicit call is the standard, idiomatic
        // Laravel pattern (every official starter kit does the same) and
        // is kept for defense-in-depth/clarity — but note neither this nor
        // Auth::attempt()'s internal regenerate(true) clears session
        // *data* (Illuminate\Session\Store::migrate() only rotates the ID
        // and optionally destroys the old ID's storage row; it never
        // touches $this->attributes). So session-stored values such as
        // `url.intended`, however they got there, survive this call
        // regardless of the $destroy argument — see the guard below for
        // the part that actually matters for that.
        $request->session()->regenerate(true);

        // `url.intended` is ambient session state, not something scoped to
        // "this specific login attempt". Laravel's Authenticate middleware
        // sets it on ANY unauthenticated request to a protected route,
        // including one that lands on this browser's session in the gap
        // between one user logging out and a completely different user
        // logging in on the same browser (e.g. a stray auto-refresh of a
        // stale tab still pointed at a landlord-only page). EnsureLandlord
        // already 403s a non-landlord user who actually reaches a
        // /landlord/* page, but there's no reason to even compute that as
        // this user's post-login redirect target in the first place.
        // Discard it if it points somewhere this specific user isn't
        // authorized for the one privilege tier that matters here.
        $intendedPath = parse_url((string) $request->session()->get('url.intended'), PHP_URL_PATH);

        if (is_string($intendedPath) && str_starts_with($intendedPath, '/landlord') && ! $request->user()->is_landlord) {
            $request->session()->forget('url.intended');
        }

        // A landlord with no workspace of their own (e.g. the bootstrap
        // `cncms:grant-landlord --create` user) would otherwise land on
        // /dashboard and hit ResolveTenantWeb's "no tenant yet" 403. Send
        // them to the platform area instead — that's where they belong.
        $user = $request->user();

        if ($user->is_landlord
            && ! $request->session()->has('url.intended')
            && ! TenantUserIndex::query()->where('user_id', $user->id)->exists()) {
            return redirect()->route('landlord.tenants.index');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
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
