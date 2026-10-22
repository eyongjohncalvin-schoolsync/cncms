<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\LoginChallenge;
use App\Models\TenantUserIndex;
use App\Models\User;
use App\Services\LoginChallengeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The shared tail of every web sign-in path (password, Google, email code,
 * two-factor challenge): once a first factor has passed, either sign the
 * user in or — when they have two-factor on — park them on the two-factor
 * challenge without signing them in.
 */
trait LogsUsersIn
{
    public const string CHALLENGE_SESSION_KEY = 'login.challenge_token';

    /**
     * @param  LoginChallenge::FACTOR_*  $firstFactor
     */
    protected function proceedAfterFirstFactor(Request $request, User $user, string $firstFactor): RedirectResponse
    {
        if ($user->hasTwoFactorEnabled()) {
            $token = app(LoginChallengeService::class)->start($user, $firstFactor);

            $request->session()->put(self::CHALLENGE_SESSION_KEY, $token);

            return redirect()->route('two-factor.challenge');
        }

        return $this->completeWebLogin($request, $user);
    }

    protected function completeWebLogin(Request $request, User $user): RedirectResponse
    {
        $request->session()->forget(self::CHALLENGE_SESSION_KEY);

        // No "remember me" option exists on the login page, so this is a
        // session-only login (no persistent recaller cookie).
        Auth::login($user);

        // Rotates the session ID (destroying the old row) — standard
        // session-fixation defence; session *data* such as `url.intended`
        // survives, hence the guard below.
        $request->session()->regenerate(true);

        // `url.intended` is ambient session state, not scoped to this
        // login: a stray request to a landlord page between one user
        // logging out and another logging in on the same browser can leave
        // it pointing at /landlord. Never send a non-landlord there.
        $intendedPath = parse_url((string) $request->session()->get('url.intended'), PHP_URL_PATH);

        if (is_string($intendedPath) && str_starts_with($intendedPath, '/landlord') && ! $user->is_landlord) {
            $request->session()->forget('url.intended');
        }

        // A landlord with no workspace of their own (e.g. the bootstrap
        // `cncms:grant-landlord --create` user) would otherwise land on
        // /dashboard and hit ResolveTenantWeb's "no tenant yet" 403.
        if ($user->is_landlord
            && ! $request->session()->has('url.intended')
            && ! TenantUserIndex::query()->where('user_id', $user->id)->exists()) {
            return redirect()->route('landlord.dashboard');
        }

        return redirect()->intended(route('dashboard'));
    }
}
