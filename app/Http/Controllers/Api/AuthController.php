<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

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
        ]);
    }
}
