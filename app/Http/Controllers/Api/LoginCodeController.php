<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IssuesApiTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendLoginCodeRequest;
use App\Http\Requests\VerifyLoginCodeRequest;
use App\Models\LoginChallenge;
use App\Models\SecurityCode;
use App\Models\User;
use App\Services\SecurityCodeService;
use Illuminate\Http\JsonResponse;

/**
 * Passwordless API sign-in with a 6-digit code sent by email. `send`
 * answers identically whether or not the account exists. `verify` returns
 * the same shape as POST /auth/login: a token, or — with two-factor on —
 * a challenge that only the authenticator app / a recovery code can pass
 * (an email code is never both factors).
 */
class LoginCodeController extends Controller
{
    use IssuesApiTokens;

    public function __construct(private readonly SecurityCodeService $codes) {}

    public function send(SendLoginCodeRequest $request): JsonResponse
    {
        $user = User::findForLogin($request->validated('identifier'));

        if ($user?->isActive()) {
            $this->codes->issue($user, SecurityCode::PURPOSE_LOGIN);
        }

        return response()->json([
            'message' => 'If that account exists, we have emailed it a 6-digit code. It expires in 10 minutes.',
        ]);
    }

    public function verify(VerifyLoginCodeRequest $request): JsonResponse
    {
        $user = User::findForLogin($request->validated('identifier'));

        $valid = $user?->isActive()
            && $this->codes->verify($user, SecurityCode::PURPOSE_LOGIN, $request->validated('code')) !== null;

        if (! $valid) {
            return response()->json([
                'message' => 'That code is wrong or has expired.',
                'errors' => ['code' => ['That code is wrong or has expired.']],
                'code' => 'INVALID_CODE',
            ], 422);
        }

        return $this->respondAfterFirstFactor($user, LoginChallenge::FACTOR_EMAIL_CODE, $request->validated('device_name'));
    }
}
