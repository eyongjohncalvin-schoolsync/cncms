<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmPasswordRequest;
use App\Http\Requests\DisableTwoFactorRequest;
use App\Http\Requests\RequestEmailChangeRequest;
use App\Http\Requests\SecurityCodeRequest;
use App\Services\AccountSecurityService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile app's account-security endpoints (auth:sanctum only, no
 * tenant needed — everything here is the central `users` row): security
 * overview, email change with a code, authenticator-app two-factor, and
 * signed-in sessions/devices. Mirrors the web "My account" page
 * (AccountController / AccountTwoFactorController) over the same services.
 */
class AccountSecurityController extends Controller
{
    public function __construct(
        private readonly AccountSecurityService $account,
        private readonly TwoFactorService $twoFactor,
    ) {}

    /** GET /auth/security */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'email' => $user->email,
            'pending_email' => $this->account->pendingEmail($user),
            'two_factor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending_setup' => ! $user->hasTwoFactorEnabled() && $user->two_factor_secret !== null,
                'recovery_codes_remaining' => $this->twoFactor->recoveryCodesRemaining($user),
            ],
        ]);
    }

    /** POST /auth/email — sends a code to the new address. */
    public function requestEmailChange(RequestEmailChangeRequest $request): JsonResponse
    {
        $this->account->requestEmailChange($request->user(), $request->validated('email'));

        return response()->json([
            'message' => "We sent a 6-digit code to {$request->validated('email')}.",
            'pending_email' => $request->validated('email'),
        ]);
    }

    /** POST /auth/email/confirm */
    public function confirmEmailChange(SecurityCodeRequest $request): JsonResponse
    {
        $this->account->confirmEmailChange($request->user(), $request->validated('code'));

        return response()->json([
            'message' => 'Email address changed.',
            'email' => $request->user()->email,
        ]);
    }

    /** DELETE /auth/email */
    public function cancelEmailChange(Request $request): JsonResponse
    {
        $this->account->cancelEmailChange($request->user());

        return response()->json(['message' => 'Email change cancelled.']);
    }

    /**
     * POST /auth/two-factor/setup — a fresh secret, its otpauth:// link and
     * a QR code PNG (data URI) for the authenticator app.
     */
    public function setupTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already on.', 'code' => 'ALREADY_ENABLED'], 409);
        }

        $this->twoFactor->beginSetup($user);

        return response()->json($this->twoFactor->pendingSetup($user));
    }

    /** POST /auth/two-factor/confirm — returns the recovery codes, once. */
    public function confirmTwoFactor(SecurityCodeRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->confirm($request->user(), $request->validated('code'));

        if ($codes === null) {
            return $this->invalidCode('That code is not valid. Check the time on your phone and try again.');
        }

        return response()->json(['message' => 'Two-factor authentication is on.', 'recovery_codes' => $codes]);
    }

    /** DELETE /auth/two-factor/setup — abandon an unconfirmed setup. */
    public function cancelTwoFactorSetup(Request $request): JsonResponse
    {
        if (! $request->user()->hasTwoFactorEnabled()) {
            $this->twoFactor->disable($request->user());
        }

        return response()->json(['message' => 'Setup cancelled.']);
    }

    /** DELETE /auth/two-factor — password + authenticator/recovery code. */
    public function disableTwoFactor(DisableTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();
        $code = $request->validated('code');

        if (! $this->twoFactor->verifyCode($user, $code) && ! $this->twoFactor->useRecoveryCode($user, $code)) {
            return $this->invalidCode('That code is not valid.');
        }

        $this->twoFactor->disable($user);

        return response()->json(['message' => 'Two-factor authentication is off.']);
    }

    /** POST /auth/two-factor/recovery-codes — password required. */
    public function regenerateRecoveryCodes(ConfirmPasswordRequest $request): JsonResponse
    {
        if (! $request->user()->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is off.', 'code' => 'NOT_ENABLED'], 409);
        }

        return response()->json([
            'message' => 'New recovery codes generated. The old ones no longer work.',
            'recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($request->user()),
        ]);
    }

    /** GET /auth/sessions — web sessions and app devices. */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'sessions' => $this->account->webSessions($user),
            'devices' => $this->account->devices($user, $user->currentAccessToken()->id),
        ]);
    }

    /** DELETE /auth/sessions — everything except this device. */
    public function signOutOtherSessions(ConfirmPasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->account->signOutOtherSessions($user, null, $user->currentAccessToken()->id);

        return response()->json(['message' => 'Signed out of all other sessions and devices.']);
    }

    private function invalidCode(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => ['code' => [$message]],
            'code' => 'INVALID_CODE',
        ], 422);
    }
}
