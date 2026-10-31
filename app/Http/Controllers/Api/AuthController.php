<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IssuesApiTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Company;
use App\Models\DevicePushToken;
use App\Models\LoginChallenge;
use App\Models\LoginFailure;
use App\Models\User;
use App\Services\AccountSecurityService;
use App\Services\LoginGuard;
use App\Support\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use IssuesApiTokens;

    /**
     * Issue a Sanctum token for a valid email/username + password.
     *
     * The login endpoint runs before tenant resolution (it is excluded
     * from App\Http\Middleware\ResolveTenant). A passive account gets a 403;
     * a user with two-factor on gets `{two_factor_required: true,
     * challenge_token, methods}` and NO token — the token comes from
     * POST /auth/two-factor (Api\TwoFactorChallengeController). The
     * optional `device_name` names the token in "Signed-in devices".
     */
    public function login(Request $request, LoginGuard $loginGuard): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required_without:username', 'string'],
            'username' => ['required_without:email', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $identifier = $request->input('email', $request->input('username'));
        $field = $request->filled('email') ? 'email' : 'username';

        $user = User::query()->where($field, $identifier)->first();

        // Repeated wrong passwords block the account, or the device, for a
        // while (App\Services\LoginGuard): 423 ACCOUNT_BLOCKED or
        // DEVICE_BLOCKED, whose message the app shows as is.
        if ($refusal = $loginGuard->refusal($user, (string) $identifier, $request)) {
            $loginGuard->recordBlockedAttempt($user, (string) $identifier, LoginFailure::MOBILE, $request);

            return $this->blockedResponse($refusal);
        }

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            $loginGuard->recordFailure($user, (string) $identifier, LoginFailure::MOBILE, $request);

            if ($refusal = $loginGuard->refusal($user, (string) $identifier, $request)) {
                return $this->blockedResponse($refusal);
            }

            throw new AuthenticationException('These credentials do not match our records.');
        }

        $loginGuard->clear($user, (string) $identifier);

        return $this->respondAfterFirstFactor($user, LoginChallenge::FACTOR_PASSWORD, $request->input('device_name'));
    }

    /**
     * @param  array{code: string, until: Carbon, message: string}  $refusal  LoginGuard::refusal()
     */
    private function blockedResponse(array $refusal): JsonResponse
    {
        return response()->json([
            'message' => $refusal['message'],
            'code' => $refusal['code'],
            'blocked_until' => $refusal['until']->toIso8601String(),
        ], 423);
    }

    /**
     * The signed-in person's quiet hours for phone alerts (Cameroon time):
     * urgent alerts arrive silently in between; emergencies still ring.
     */
    public function alertSettings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quietHours($request->user())]);
    }

    public function updateAlertSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quiet_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_end', 'different:quiet_end'],
            'quiet_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_start'],
        ]);

        $request->user()->forceFill([
            'alert_quiet_start' => $validated['quiet_start'] ?? null,
            'alert_quiet_end' => $validated['quiet_end'] ?? null,
        ])->save();

        return response()->json(['data' => $this->quietHours($request->user())]);
    }

    /**
     * @return array{quiet_start: string|null, quiet_end: string|null}
     */
    private function quietHours(User $user): array
    {
        return ['quiet_start' => $user->alert_quiet_start, 'quiet_end' => $user->alert_quiet_end];
    }

    /**
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        // This phone stops getting the signed-out person's alerts (a shared
        // phone must not ring for someone else). Other phones keep theirs.
        if (is_string($request->input('device_id')) && $request->input('device_id') !== '') {
            DevicePushToken::query()->where('user_id', $request->user()->id)->where('device_id', $request->input('device_id'))->update(['is_valid' => false]);
        }

        return response()->json([
            'message' => 'Logged out',
        ]);
    }

    /**
     * Diagnostic "who am I" endpoint, protected by both auth:sanctum and
     * ResolveTenant. Demonstrates (and is used by tests to exercise) the
     * mechanism downstream controllers/policies use to read the resolved
     * tenant role: App\Support\TenantContext, injected here via DI.
     */
    public function me(Request $request, TenantContext $context): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
            ],
            'role' => $context->role,
            // RBAC v2 (docs/plans/rbac-v2-configurable-roles.md): the
            // resolved permission list for this role, or ['*'] for a super
            // role. Mirrors HandleInertiaRequests::share()'s
            // auth.user.permissions. Wave 1 only exposes it — the mobile
            // guards still key off `role` until Wave 4.
            'permissions' => $context->permissions(),
            // The tenant's name and logo for the app header; logo_url is
            // fetched with the Bearer token (GET /company/logo).
            'company' => [
                'name' => Company::cached()?->name ?? tenant()?->name,
                'logo_url' => Company::cached()?->logoUrl(forApi: true),
            ],
        ]);
    }

    /**
     * PATCH /auth/profile — self-service update of the authenticated user's
     * own name/username/email. Deliberately resolves ONLY from
     * $request->user() — there is no route parameter on this endpoint at
     * all, so it can never be pointed at another user's row regardless of
     * role, the same "no way to redirect elsewhere" guarantee
     * AgentController::me() already establishes for the mobile Agent
     * Profile screen. `status`/`password`/anything else can never surface
     * here — UpdateProfileRequest::rules() doesn't define those keys, so
     * $request->validated() only ever contains name/username/email.
     *
     * The email may only be sent unchanged: a new address must be confirmed
     * with a code (POST /auth/email, Api\AccountSecurityController).
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update(Arr::except($request->validated(), ['email']));

        return response()->json([
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * PATCH /auth/password — self-service password change. Requires proof
     * of the CURRENT password via Hash::check() before accepting a new one
     * — a valid session token alone is never enough, since a device left
     * unlocked or borrowed mid-shift can hold a valid token without whoever
     * is holding it actually knowing the account's password (the same
     * "stolen unlocked phone" concern mobile-app-react-native.md §7 raises
     * for payment actions, applied here to account takeover).
     *
     * On success, revokes every OTHER active Sanctum token for this user —
     * this request's own current token is explicitly excluded, so the
     * device making this call is never logged out by its own action. This
     * is a standard "password change ends other sessions" security default.
     * Real UX consequence, called out here since it's genuinely visible to
     * an agent: if this account is also logged in on a second
     * device/session, that other session's token stops working immediately
     * and it will need to log back in with the new password. Accepted at
     * this app's current scale (~6 users, one tenant) — a password change
     * is rare, and it's exactly the moment an old/unexpected session
     * SHOULD stop working.
     */
    public function updatePassword(UpdatePasswordRequest $request, AccountSecurityService $account): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // Also ends every web session and emails the owner a notice.
        $account->changePassword(
            $user,
            $request->validated('new_password'),
            null,
            $user->currentAccessToken()->id,
        );

        return response()->json([
            'message' => 'Password updated.',
        ]);
    }
}
