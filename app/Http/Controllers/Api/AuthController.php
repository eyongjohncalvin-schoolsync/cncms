<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Issue a Sanctum token for a valid email/username + password.
     *
     * The login endpoint runs before tenant resolution (it is excluded
     * from App\Http\Middleware\ResolveTenant), so it does not initialize
     * tenancy itself. It best-effort looks up the caller's role for the
     * single current tenant ("swecom") purely for display purposes in the
     * response payload — the authoritative role check for every
     * subsequent request happens in ResolveTenant.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required_without:username', 'string'],
            'username' => ['required_without:email', 'string'],
            'password' => ['required', 'string'],
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

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw new AuthenticationException('These credentials do not match our records.');
        }

        $token = $user->createToken('api')->plainTextToken;

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
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

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
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update($request->validated());

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
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // The 'password' attribute is cast to 'hashed' on the User model,
        // so Eloquent hashes this automatically on save (same convention as
        // SettingsUserController::store()).
        $user->update(['password' => $request->validated('new_password')]);

        $currentTokenId = $request->user()->currentAccessToken()->id;

        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'message' => 'Password updated.',
        ]);
    }
}
