<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

        if (! Auth::attempt([$field => $credentials['username'], 'password' => $credentials['password']], true)) {
            throw ValidationException::withMessages([
                'username' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
