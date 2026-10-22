<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsUsersIn;
use App\Models\LoginChallenge;
use App\Models\TenantUserIndex;
use App\Models\User;
use App\Support\GeneratesUsername;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Google OAuth sign-up/sign-in — see
 * .ai/skills/cncms/cncms-context/references/self-service-onboarding.md
 * section 7. Matches by email against the central `users` table:
 *
 *   - Matching user WITH a tenant already (TenantUserIndex entry exists)
 *     -> log in, straight to the dashboard (ResolveTenantWeb resolves
 *     their tenant normally from there — including the approval gate, if
 *     their workspace is still pending).
 *   - Matching user with NO tenant yet -> log in, send them to the
 *     company-info-only workspace-creation form
 *     (App\Http\Controllers\RegisterController::workspace()).
 *   - No matching user -> create one (random unusable password, since this
 *     account can only ever authenticate via Google; email_verified_at set
 *     immediately, Google already verified it), then same as above.
 *
 * Deliberately outside ['auth', 'tenant.web'] (routes/web/register.php) —
 * both actions must be reachable by a guest, and the callback needs to run
 * before any tenant is resolved.
 */
class GoogleAuthController extends Controller
{
    use GeneratesUsername;
    use LogsUsersIn;

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::query()->where('email', $googleUser->getEmail())->first();

        if (! $user) {
            $user = User::query()->create([
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: $googleUser->getEmail(),
                'username' => $this->generateUsername($googleUser->getEmail(), (string) $googleUser->getName()),
                'email' => $googleUser->getEmail(),
                // Unusable directly — this account can only authenticate
                // via Google. Cast to 'hashed' on the User model.
                'password' => Hash::make(Str::random(40)),
                'status' => 'active',
            ]);

            // 'email_verified_at' is deliberately NOT in User's #[Fillable]
            // list (only Settings > Users' admin-driven creation flow would
            // ever need to mass-assign it, and it doesn't), so mass
            // assignment above silently drops it — set it explicitly here.
            // Google already verified this email, unlike a classic
            // email/password signup (see RegisterController::store()).
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if (! $user->isActive()) {
            return redirect()->route('login')->withErrors([
                'username' => 'This account has been deactivated. Contact your administrator.',
            ]);
        }

        // Google counts as the first factor only: with two-factor on, the
        // authenticator step is still required.
        if ($user->hasTwoFactorEnabled()) {
            return $this->proceedAfterFirstFactor($request, $user, LoginChallenge::FACTOR_PASSWORD);
        }

        Auth::login($user);

        if (TenantUserIndex::query()->where('user_id', $user->id)->exists()) {
            return redirect()->route('dashboard');
        }

        // A landlord with no workspace belongs in the platform area, not the
        // "create your workspace" form.
        if ($user->is_landlord) {
            return redirect()->route('landlord.tenants.index');
        }

        return redirect()->route('register.workspace');
    }
}
