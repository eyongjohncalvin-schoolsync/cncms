<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmPasswordRequest;
use App\Http\Requests\RequestEmailChangeRequest;
use App\Http\Requests\SecurityCodeRequest;
use App\Http\Requests\UpdateAccountPasswordRequest;
use App\Http\Requests\UpdateAccountProfileRequest;
use App\Services\AccountSecurityService;
use App\Services\TwoFactorService;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My account" (/account): the signed-in user's own profile, password,
 * email (changed via a code sent to the new address), two-factor
 * (AccountTwoFactorController) and signed-in sessions/devices. Every write
 * route is `not-impersonating`: a landlord logged in as this user can look,
 * but cannot change their credentials.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountSecurityService $account,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Show', [
            'account' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'locale' => $user->locale ?? app()->getLocale(),
                'pending_email' => $this->account->pendingEmail($user),
            ],
            'twoFactor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'recovery_codes_remaining' => $this->twoFactor->recoveryCodesRemaining($user),
                'setup' => $this->twoFactor->pendingSetup($user),
            ],
            // Flashed once by confirm()/regenerate — never stored readable.
            'recoveryCodes' => $request->session()->get('recovery_codes'),
            'sessions' => $this->account->webSessions($user, $request->session()->getId()),
            'devices' => $this->account->devices($user),
            'isImpersonating' => Impersonation::active(),
            // Which shell to render: the landlord's /landlord/account or the
            // workspace's /account (same page, same actions).
            'area' => $request->routeIs('landlord.*') ? 'landlord' : 'workspace',
        ]);
    }

    public function updateProfile(UpdateAccountProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        $request->session()->put('locale', $request->validated('locale'));

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(UpdateAccountPasswordRequest $request): RedirectResponse
    {
        $this->account->changePassword(
            $request->user(),
            $request->validated('password'),
            $request->session()->getId(),
        );

        return back()->with('success', 'Password changed. Your other sessions and devices were signed out.');
    }

    public function requestEmailChange(RequestEmailChangeRequest $request): RedirectResponse
    {
        $this->account->requestEmailChange($request->user(), $request->validated('email'));

        return back()->with('success', "We sent a 6-digit code to {$request->validated('email')}. Enter it below to confirm the change.");
    }

    public function confirmEmailChange(SecurityCodeRequest $request): RedirectResponse
    {
        $this->account->confirmEmailChange($request->user(), $request->validated('code'));

        return back()->with('success', 'Email address changed.');
    }

    public function cancelEmailChange(Request $request): RedirectResponse
    {
        $this->account->cancelEmailChange($request->user());

        return back()->with('success', 'Email change cancelled.');
    }

    /** "Sign out of all other sessions and devices" (password required). */
    public function signOutOtherSessions(ConfirmPasswordRequest $request): RedirectResponse
    {
        $this->account->signOutOtherSessions($request->user(), $request->session()->getId());

        return back()->with('success', 'Signed out of all other sessions and devices.');
    }
}
