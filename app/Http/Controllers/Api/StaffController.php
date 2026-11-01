<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantUserRequest;
use App\Models\Agent;
use App\Models\Role;
use App\Models\TenantUser;
use App\Services\LoginGuard;
use App\Services\StaffAccountService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff on the phone (docs/plans/mobile-manager-app.md, wave 5): the
 * everyday people decisions — change someone's role, deactivate or
 * reactivate them, let them sign in again after too many wrong passwords.
 * The same policy (TenantUserPolicy), request (UpdateTenantUserRequest:
 * never your own role, never the last super) and service
 * (StaffAccountService) as the Users Control Center; creating accounts,
 * branches, salaries and the role matrix stay on the web.
 */
class StaffController extends Controller
{
    public function __construct(
        private readonly StaffAccountService $accounts,
        private readonly LoginGuard $loginGuard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TenantUser::class);

        $context = $this->context();
        $me = $request->user();
        $labels = Role::query()->pluck('label', 'name');

        $members = TenantUser::query()
            ->where('tenant_id', $context->tenantUser->tenant_id)
            // A fenced manager sees their branch's staff, as on the web.
            ->when($context->isFenced(), fn ($query) => $query->where(fn ($inner) => $inner
                ->where('branch_id', $context->branchId)
                ->orWhere(fn ($agents) => $agents->where('role', 'agent')->whereIn('user_id', Agent::query()->whereNotNull('user_id')->pluck('user_id')))))
            ->with(['user', 'branch'])
            ->get()
            ->filter(fn (TenantUser $member): bool => $member->user !== null)
            ->sortBy(fn (TenantUser $member): string => mb_strtolower((string) $member->user->name));

        return response()->json(['data' => [
            'can_manage' => $context->can('users.manage'),
            'roles' => Role::query()->orderByDesc('is_system')->orderBy('label')->get(['name', 'label'])
                ->map(fn (Role $role): array => ['name' => $role->name, 'label' => $role->label])->values()->all(),
            'staff' => $members->map(function (TenantUser $member) use ($me, $labels): array {
                $blockedUntil = $this->loginGuard->blockedUntil($member->user, '');

                return [
                    'id' => $member->id,
                    'name' => $member->user->name,
                    'username' => $member->user->username,
                    'role' => $member->role,
                    'role_label' => (string) ($labels[$member->role] ?? $member->role),
                    'job_title' => $member->job_title,
                    'branch_name' => $member->branch?->name,
                    'status' => $member->user->status,
                    'sign_in_blocked_until' => $blockedUntil?->toIso8601String(),
                    'is_me' => $member->user_id === $me->id,
                    'can_manage' => $member->user_id !== $me->id && $me->can('update', $member) && ! $member->user->is_landlord,
                ];
            })->values()->all(),
        ]]);
    }

    /** UpdateTenantUserRequest: never your own role, never the last super. */
    public function role(UpdateTenantUserRequest $request, TenantUser $tenantUser): JsonResponse
    {
        $request->validate(['role' => ['required']]);

        $this->accounts->update($tenantUser, ['role' => $request->validated('role')], $request->user());

        return $this->done("{$tenantUser->user->name} is now ".(Role::query()->where('name', $tenantUser->role)->value('label') ?? $tenantUser->role).'.');
    }

    public function deactivate(Request $request, TenantUser $tenantUser): JsonResponse
    {
        $this->authorize('deactivate', $tenantUser);
        abort_if($tenantUser->user_id === $request->user()->id, 422, 'You cannot deactivate yourself.');

        $this->accounts->deactivate($tenantUser);

        return $this->done("{$tenantUser->user->name} is deactivated and signed out everywhere.");
    }

    public function activate(Request $request, TenantUser $tenantUser): JsonResponse
    {
        $this->authorize('activate', $tenantUser);

        $this->accounts->activate($tenantUser);

        return $this->done("{$tenantUser->user->name} can sign in again.");
    }

    /**
     * Their wrong passwords stop counting, so the block lifts now — a
     * colleague locked out by their own typing, or after a check that the
     * attempts were theirs. Device blocks stay with the landlord.
     */
    public function unblock(Request $request, TenantUser $tenantUser): JsonResponse
    {
        $this->authorize('update', $tenantUser);

        $this->loginGuard->clear($tenantUser->user, '');

        return $this->done("{$tenantUser->user->name} can sign in with their password again.");
    }

    private function done(string $message): JsonResponse
    {
        return response()->json(['message' => $message]);
    }

    /**
     * Resolved per call, not injected: the router keeps a controller instance
     * for the life of the app, and the tenant context is per request.
     */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
