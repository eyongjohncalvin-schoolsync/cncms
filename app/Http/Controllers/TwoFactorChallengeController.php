<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsUsersIn;
use App\Http\Requests\TwoFactorChallengeRequest;
use App\Models\LoginChallenge;
use App\Services\LoginChallengeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web two-factor challenge: the page a user with two-factor on lands on
 * after their first factor (password, Google or an email code). They are
 * NOT signed in until a valid second factor is entered; the pending
 * challenge token lives only in the session (LogsUsersIn).
 */
class TwoFactorChallengeController extends Controller
{
    use LogsUsersIn;

    public function __construct(private readonly LoginChallengeService $challenges) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $challenge = $this->pendingChallenge($request);

        if (! $challenge) {
            return $this->restartLogin($request, 'Your sign-in expired. Please sign in again.');
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'methods' => $challenge->methods(),
            'emailHint' => $challenge->user->maskedEmail(),
        ]);
    }

    public function store(TwoFactorChallengeRequest $request): RedirectResponse
    {
        $challenge = $this->pendingChallenge($request);

        if (! $challenge) {
            return $this->restartLogin($request, 'Your sign-in expired. Please sign in again.');
        }

        if ($this->challenges->passes($challenge, $request->validated('method'), $request->validated('code'))) {
            return $this->completeWebLogin($request, $challenge->user);
        }

        if ($this->challenges->exhausted($challenge->refresh())) {
            return $this->restartLogin($request, 'Too many wrong codes. Please sign in again.');
        }

        return back()->withErrors(['code' => 'That code is not valid. Try again.']);
    }

    /**
     * "Email me a code instead" — for someone without their phone. Only
     * offered after a password first factor.
     */
    public function sendEmail(Request $request): RedirectResponse
    {
        $challenge = $this->pendingChallenge($request);

        if (! $challenge) {
            return $this->restartLogin($request, 'Your sign-in expired. Please sign in again.');
        }

        if (! $this->challenges->sendEmailCode($challenge)) {
            return back()->withErrors(['code' => 'Use your authenticator app or a recovery code.']);
        }

        return back()->with('success', "We emailed a 6-digit code to {$challenge->user->maskedEmail()}.");
    }

    private function pendingChallenge(Request $request): ?LoginChallenge
    {
        return $this->challenges->find($request->session()->get(self::CHALLENGE_SESSION_KEY));
    }

    private function restartLogin(Request $request, string $message): RedirectResponse
    {
        $request->session()->forget(self::CHALLENGE_SESSION_KEY);

        return redirect()->route('login')->withErrors(['username' => $message]);
    }
}
