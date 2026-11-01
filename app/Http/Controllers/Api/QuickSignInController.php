<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IssuesApiTokens;
use App\Http\Controllers\Controller;
use App\Models\LoginFailure;
use App\Services\LoginGuard;
use App\Services\QuickSignInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fingerprint sign-in on the phone — see App\Services\QuickSignInService.
 */
class QuickSignInController extends Controller
{
    use IssuesApiTokens;

    public function __construct(private readonly QuickSignInService $quickSignIn) {}

    /**
     * Turns fingerprint sign-in on for this phone: the key the phone keeps
     * behind the fingerprint.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['device_name' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->quickSignIn->issue($request->user(), (string) ($validated['device_name'] ?? '')), 201);
    }

    /** Turns it off: the phone's key stops working. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->quickSignIn->revoke($request->user(), $id);

        return response()->json(['message' => 'Fingerprint sign-in turned off']);
    }

    /**
     * Trades a key for a normal token. Same refusals as a password
     * sign-in: an unknown or revoked key, a blocked or deactivated account.
     * Two-factor was passed when the key was issued.
     */
    public function login(Request $request, LoginGuard $loginGuard): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $this->quickSignIn->redeem($validated['key']);

        if ($user === null) {
            return response()->json([
                'message' => 'Fingerprint sign-in is no longer set up for this phone. Sign in with your password.',
                'code' => 'QUICK_SIGN_IN_INVALID',
            ], 401);
        }

        if ($refusal = $loginGuard->refusal($user, (string) $user->username, $request)) {
            $loginGuard->recordBlockedAttempt($user, (string) $user->username, LoginFailure::MOBILE, $request);

            return response()->json([
                'message' => $refusal['message'],
                'code' => $refusal['code'],
                'blocked_until' => $refusal['until']->toIso8601String(),
            ], 423);
        }

        if (! $user->isActive()) {
            return $this->deactivatedResponse();
        }

        return $this->tokenResponse($user, $validated['device_name'] ?? null);
    }
}
