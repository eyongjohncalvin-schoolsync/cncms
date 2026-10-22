<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmPasswordRequest;
use App\Http\Requests\DisableTwoFactorRequest;
use App\Http\Requests\SecurityCodeRequest;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "My account" two-factor section: set up (QR + secret, confirmed with a
 * code), turn off (password + code) and new recovery codes (password).
 * Freshly generated recovery codes are flashed once to the page.
 */
class AccountTwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasTwoFactorEnabled()) {
            return back()->with('error', 'Two-factor authentication is already on.');
        }

        $this->twoFactor->beginSetup($request->user());

        return back();
    }

    public function confirm(SecurityCodeRequest $request): RedirectResponse
    {
        $codes = $this->twoFactor->confirm($request->user(), $request->validated('code'));

        if ($codes === null) {
            return back()->withErrors(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }

        return back()
            ->with('success', 'Two-factor authentication is on.')
            ->with('recovery_codes', $codes);
    }

    /** Abandon a setup that was never confirmed. */
    public function cancelSetup(Request $request): RedirectResponse
    {
        if (! $request->user()->hasTwoFactorEnabled()) {
            $this->twoFactor->disable($request->user());
        }

        return back();
    }

    public function destroy(DisableTwoFactorRequest $request): RedirectResponse
    {
        $user = $request->user();
        $code = $request->validated('code');

        if (! $this->twoFactor->verifyCode($user, $code) && ! $this->twoFactor->useRecoveryCode($user, $code)) {
            return back()->withErrors(['code' => 'That code is not valid.']);
        }

        $this->twoFactor->disable($user);

        return back()->with('success', 'Two-factor authentication is off.');
    }

    public function regenerateRecoveryCodes(ConfirmPasswordRequest $request): RedirectResponse
    {
        if (! $request->user()->hasTwoFactorEnabled()) {
            return back()->with('error', 'Two-factor authentication is off.');
        }

        return back()
            ->with('success', 'New recovery codes generated. The old ones no longer work.')
            ->with('recovery_codes', $this->twoFactor->regenerateRecoveryCodes($request->user()));
    }
}
