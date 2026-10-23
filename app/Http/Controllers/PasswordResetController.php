<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\AccountSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web "Forgot password?": step 1 emails a 6-digit reset code
 * (SecurityCodeService, purpose `password_reset`), step 2 takes the code
 * and the new password. The answer to step 1 is the same whether or not the
 * account exists or is active. A successful reset signs the account out
 * everywhere and does NOT sign anyone in: the user then signs in normally,
 * so two-factor (left untouched by the reset) still applies.
 */
class PasswordResetController extends Controller
{
    public const string IDENTIFIER_SESSION_KEY = 'password_reset.identifier';

    public const string SENT_MESSAGE = 'If that account exists, we have emailed it a 6-digit code. It expires in 10 minutes.';

    public const string INVALID_CODE_MESSAGE = 'That code is wrong or has expired.';

    public function __construct(private readonly AccountSecurityService $account) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'identifier' => $request->session()->get(self::IDENTIFIER_SESSION_KEY),
        ]);
    }

    public function send(ForgotPasswordRequest $request): RedirectResponse
    {
        $identifier = trim($request->validated('identifier'));

        $this->account->sendPasswordResetCode($identifier);

        $request->session()->put(self::IDENTIFIER_SESSION_KEY, $identifier);

        return redirect()->route('password.request')->with('success', self::SENT_MESSAGE);
    }

    public function reset(ResetPasswordRequest $request): RedirectResponse
    {
        $reset = $this->account->resetPassword(
            trim($request->validated('identifier')),
            $request->validated('code'),
            $request->validated('password'),
        );

        if (! $reset) {
            return back()->withErrors(['code' => self::INVALID_CODE_MESSAGE]);
        }

        $request->session()->forget(self::IDENTIFIER_SESSION_KEY);

        return redirect()->route('login')->with('success', 'Your password has been reset. Sign in with your new password.');
    }

    /** "Use a different account" — back to the first step. */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::IDENTIFIER_SESSION_KEY);

        return redirect()->route('password.request');
    }
}
