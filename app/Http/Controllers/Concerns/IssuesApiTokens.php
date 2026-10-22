<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\LoginChallenge;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\LoginChallengeService;
use Illuminate\Http\JsonResponse;

/**
 * The shared tail of every API sign-in path (password, email code,
 * two-factor challenge): refuse passive accounts, hand back a two-factor
 * challenge instead of a token when two-factor is on, otherwise issue the
 * Sanctum token.
 */
trait IssuesApiTokens
{
    /**
     * @param  LoginChallenge::FACTOR_*  $firstFactor
     */
    protected function respondAfterFirstFactor(User $user, string $firstFactor, ?string $deviceName): JsonResponse
    {
        if (! $user->isActive()) {
            return $this->deactivatedResponse();
        }

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => app(LoginChallengeService::class)->start($user, $firstFactor),
                'methods' => LoginChallenge::methodsFor($firstFactor),
                'email_hint' => $user->maskedEmail(),
                'expires_in' => LoginChallengeService::TTL_MINUTES * 60,
            ]);
        }

        return $this->tokenResponse($user, $deviceName);
    }

    protected function deactivatedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This account has been deactivated. Contact your administrator.',
            'code' => 'ACCOUNT_DEACTIVATED',
        ], 403);
    }

    /**
     * Issues the Sanctum token. The login endpoints run before tenant
     * resolution, so the role is looked up best-effort for the single
     * current tenant ("swecom") purely for display — the authoritative
     * role check for every later request happens in ResolveTenant.
     */
    protected function tokenResponse(User $user, ?string $deviceName): JsonResponse
    {
        $token = $user->createToken($this->tokenName($deviceName))->plainTextToken;

        $role = null;

        $tenant = Tenant::find('swecom');

        if ($tenant) {
            $wasInitialized = tenancy()->initialized;

            tenancy()->initialize($tenant);

            $role = TenantUser::query()
                ->where('user_id', $user->id)
                ->where('tenant_id', $tenant->id)
                ->value('role');

            if (! $wasInitialized) {
                tenancy()->end();
            }
        }

        return response()->json([
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $role,
            ],
            'token' => $token,
        ]);
    }

    /**
     * The token name shown in "Signed-in devices" — the phone's model when
     * the app sends one, else the historical "api".
     */
    private function tokenName(?string $deviceName): string
    {
        $deviceName = trim((string) $deviceName);

        return $deviceName === '' ? 'api' : mb_substr($deviceName, 0, 100);
    }
}
