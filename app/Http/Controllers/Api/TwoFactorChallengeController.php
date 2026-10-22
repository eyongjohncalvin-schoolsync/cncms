<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IssuesApiTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\TwoFactorChallengeRequest;
use App\Services\LoginChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API second sign-in step: the app sends back the `challenge_token` from
 * POST /auth/login (or /auth/login-code/verify) with a code, and gets the
 * Sanctum token. 422 for a wrong code; 410 CHALLENGE_EXPIRED once the
 * challenge is used, expired or out of attempts (start over).
 */
class TwoFactorChallengeController extends Controller
{
    use IssuesApiTokens;

    public function __construct(private readonly LoginChallengeService $challenges) {}

    public function store(TwoFactorChallengeRequest $request): JsonResponse
    {
        $challenge = $this->challenges->find($request->validated('challenge_token'));

        if (! $challenge) {
            return $this->expiredResponse();
        }

        if ($this->challenges->passes($challenge, $request->validated('method'), $request->validated('code'))) {
            return $this->tokenResponse($challenge->user, $request->input('device_name'));
        }

        if ($this->challenges->exhausted($challenge->refresh())) {
            return $this->expiredResponse('Too many wrong codes. Please sign in again.');
        }

        return response()->json([
            'message' => 'That code is not valid. Try again.',
            'errors' => ['code' => ['That code is not valid. Try again.']],
            'code' => 'INVALID_CODE',
        ], 422);
    }

    /** "Email me a code instead" — only after a password first factor. */
    public function sendEmail(Request $request): JsonResponse
    {
        $challenge = $this->challenges->find((string) $request->input('challenge_token'));

        if (! $challenge) {
            return $this->expiredResponse();
        }

        if (! $this->challenges->sendEmailCode($challenge)) {
            return response()->json([
                'message' => 'Use your authenticator app or a recovery code.',
                'code' => 'METHOD_NOT_ALLOWED',
            ], 422);
        }

        return response()->json([
            'message' => "We emailed a 6-digit code to {$challenge->user->maskedEmail()}.",
        ]);
    }

    /**
     * Deliberately NOT 401: the app's axios client treats every 401 as "my
     * token died" and logs out, but this caller has no token yet.
     */
    private function expiredResponse(string $message = 'Your sign-in expired. Please sign in again.'): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => 'CHALLENGE_EXPIRED'], 410);
    }
}
