<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsUsersIn;
use App\Http\Requests\SendLoginCodeRequest;
use App\Http\Requests\VerifyLoginCodeRequest;
use App\Models\LoginChallenge;
use App\Models\SecurityCode;
use App\Models\User;
use App\Services\SecurityCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Passwordless web sign-in: "Email me a sign-in code". The response to a
 * send is always the same, whether or not the username/email belongs to an
 * (active) account, so the form can't be used to discover accounts. For a
 * user with two-factor on, the code counts as the first factor only — the
 * authenticator step still follows (LogsUsersIn).
 */
class LoginCodeController extends Controller
{
    use LogsUsersIn;

    public const string IDENTIFIER_SESSION_KEY = 'login_code.identifier';

    public function __construct(private readonly SecurityCodeService $codes) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/LoginCode', [
            'identifier' => $request->session()->get(self::IDENTIFIER_SESSION_KEY),
        ]);
    }

    public function send(SendLoginCodeRequest $request): RedirectResponse
    {
        $identifier = trim($request->validated('identifier'));
        $user = User::findForLogin($identifier);

        if ($user?->isActive()) {
            $this->codes->issue($user, SecurityCode::PURPOSE_LOGIN);
        }

        $request->session()->put(self::IDENTIFIER_SESSION_KEY, $identifier);

        return redirect()->route('login-code.create')
            ->with('success', 'If that account exists, we have emailed it a 6-digit code. It expires in 10 minutes.');
    }

    public function verify(VerifyLoginCodeRequest $request): RedirectResponse
    {
        $user = User::findForLogin($request->validated('identifier'));

        $valid = $user?->isActive()
            && $this->codes->verify($user, SecurityCode::PURPOSE_LOGIN, $request->validated('code')) !== null;

        if (! $valid) {
            return back()->withErrors(['code' => 'That code is wrong or has expired.']);
        }

        $request->session()->forget(self::IDENTIFIER_SESSION_KEY);

        return $this->proceedAfterFirstFactor($request, $user, LoginChallenge::FACTOR_EMAIL_CODE);
    }

    /** "Use a different account" — back to the first step. */
    public function reset(Request $request): RedirectResponse
    {
        $request->session()->forget(self::IDENTIFIER_SESSION_KEY);

        return redirect()->route('login-code.create');
    }
}
