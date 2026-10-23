<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PasswordResetController as WebPasswordResetController;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\AccountSecurityService;
use Illuminate\Http\JsonResponse;

/**
 * API "Forgot password?" for a later mobile build: POST
 * /auth/password/forgot emails a 6-digit reset code (same answer whether or
 * not the account exists), POST /auth/password/reset sets the new password
 * with that code. A reset revokes every token and web session and issues no
 * token itself: the app signs in normally afterwards, so two-factor still
 * applies.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly AccountSecurityService $account) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->account->sendPasswordResetCode(trim($request->validated('identifier')));

        return response()->json([
            'message' => WebPasswordResetController::SENT_MESSAGE,
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $reset = $this->account->resetPassword(
            trim($request->validated('identifier')),
            $request->validated('code'),
            $request->validated('password'),
        );

        if (! $reset) {
            return response()->json([
                'message' => WebPasswordResetController::INVALID_CODE_MESSAGE,
                'errors' => ['code' => [WebPasswordResetController::INVALID_CODE_MESSAGE]],
                'code' => 'INVALID_CODE',
            ], 422);
        }

        return response()->json([
            'message' => 'Your password has been reset. Sign in with your new password.',
        ]);
    }
}
