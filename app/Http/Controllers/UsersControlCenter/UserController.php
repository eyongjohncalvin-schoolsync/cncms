<?php

declare(strict_types=1);

namespace App\Http\Controllers\UsersControlCenter;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantUserRequest;
use App\Http\Requests\UpdateTenantUserRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Repositories\Contracts\BranchRepositoryInterface;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users Control Center — Users tab (RBAC v2 Wave 3,
 * docs/plans/rbac-v2-configurable-roles.md). This is the old
 * Settings → Users & Roles screen, relocated to its own top-level nav and
 * detached from Settings. The two-connection user-creation dance, the
 * uuid→branch_id resolution, the "role != worker clears can_record_payments"
 * guard and the investor grant/revoke audit stamps are carried over
 * verbatim from the retired App\Http\Controllers\SettingsUserController —
 * see store()/update() below for the same doc comments.
 *
 * The role <select> is now populated from the tenant's own `roles` table
 * (system + custom) instead of a hardcoded 5-value list.
 *
 * Gate: index = `users.view`, mutations = `users.manage`, via
 * App\Policies\TenantUserPolicy (already permission-backed since Wave 2).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BranchRepositoryInterface $branches,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', TenantUser::class);

        $tenantUsers = TenantUser::query()
            ->where('tenant_id', $this->context->tenantUser->tenant_id)
            ->with(['user', 'branch'])
            ->get();

        return Inertia::render('UsersControlCenter/Users', [
            'users' => $tenantUsers->map(fn (TenantUser $tenantUser): array => [
                'id' => $tenantUser->id,
                'role' => $tenantUser->role,
                'job_title' => $tenantUser->job_title,
                'name' => $tenantUser->user->name,
                'username' => $tenantUser->user->username,
                'email' => $tenantUser->user->email,
                'status' => $tenantUser->user->status,
                'branch_uuid' => $tenantUser->branch?->uuid,
                'branch_name' => $tenantUser->branch?->name,
                // Only meaningful for role === 'worker' — see
                // PaymentPolicy::create()'s doc comment. Always sent so the
                // page can render a checkbox purely from this one field.
                'can_record_payments' => $tenantUser->can_record_payments,
                // Investor tier grant — see ReportPolicy::view(). Meaningful
                // on any role, so the page renders this checkbox unconditionally.
                'is_investor' => $tenantUser->is_investor,
            ])->values(),
            // Role dropdown source — every role this tenant has, system and
            // custom, newest system-first then custom by label.
            'roles' => Role::query()
                ->orderByDesc('is_system')
                ->orderBy('label')
                ->get(['name', 'label', 'is_system'])
                ->map(fn (Role $role): array => [
                    'name' => $role->name,
                    'label' => $role->label,
                    'is_system' => $role->is_system,
                ])->values(),
            // Only rendered when there are 2+ branches (a single-branch
            // tenant never sees the control — branches-and-locations.md §4).
            'branches' => $this->branches->all()->map(fn (Branch $branch): array => [
                'uuid' => $branch->uuid,
                'name' => $branch->name,
            ])->values(),
        ]);
    }

    /**
     * Creates the central User row and the tenant-scoped TenantUser row that
     * links it to the current tenant. These two inserts are on DIFFERENT
     * database connections (central `pgsql` vs the tenant connection), so
     * they cannot be wrapped in a single DB::transaction(). We run two
     * separate transactions instead: central user first, then tenant_users.
     * If the second insert fails, the central user row is left orphaned (no
     * tenant membership) — for a low-volume admin action this is an
     * acceptable tradeoff. We deliberately do NOT swallow that failure; it
     * propagates as a 500 so the admin notices and can retry.
     */
    public function store(StoreTenantUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $branchId = $this->resolveBranchId($data['branch_uuid'] ?? null);

        $user = DB::connection('pgsql')->transaction(fn (): User => User::query()->create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            // The 'password' attribute is cast to 'hashed' on the User model,
            // so Eloquent hashes this automatically on save.
            'password' => $data['password'],
            'status' => 'active',
        ]));

        DB::transaction(function () use ($user, $data, $branchId): void {
            TenantUser::query()->create([
                'user_id' => $user->id,
                'tenant_id' => $this->context->tenantUser->tenant_id,
                'role' => $data['role'],
                'job_title' => $data['job_title'] ?? null,
                'branch_id' => $branchId,
            ]);
        });

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function update(UpdateTenantUserRequest $request, TenantUser $tenantUser): RedirectResponse
    {
        $data = $request->validated();

        if (array_key_exists('branch_uuid', $data)) {
            $data['branch_id'] = $this->resolveBranchId($data['branch_uuid']);
            unset($data['branch_uuid']);
        }

        // Defensive: a role change away from 'worker' also clears the
        // payment-recording flag, so it can't silently reactivate later if
        // this same person is ever demoted back to 'worker'. The flag is
        // meaningless for every other role — see PaymentPolicy::create().
        if (array_key_exists('role', $data) && $data['role'] !== 'worker') {
            $data['can_record_payments'] = false;
        }

        // Investor grant/revoke audit trail — stamped from the CURRENT
        // central user performing this request (already authorized as
        // users.manage by UpdateTenantUserRequest::authorize()), not the
        // target row.
        if (array_key_exists('is_investor', $data)) {
            $data['investor_granted_by'] = $data['is_investor'] ? $request->user()->id : null;
            $data['investor_granted_at'] = $data['is_investor'] ? now() : null;
        }

        $tenantUser->update($data);

        return redirect()->route('users.index')->with('success', 'Saved.');
    }

    /**
     * Resolves an external-facing branch_uuid to an internal branch_id, or
     * null when no branch was picked (the "unrestricted" default).
     */
    private function resolveBranchId(?string $branchUuid): ?int
    {
        if ($branchUuid === null || $branchUuid === '') {
            return null;
        }

        $branch = $this->branches->findByUuid($branchUuid);

        if (! $branch) {
            throw ValidationException::withMessages(['branch_uuid' => ['The selected branch does not exist.']]);
        }

        return $branch->id;
    }

    public function deactivate(TenantUser $tenantUser): RedirectResponse
    {
        $this->authorize('deactivate', TenantUser::class);

        $tenantUser->user->update(['status' => 'passive']);

        return redirect()->route('users.index')->with('success', 'User deactivated.');
    }
}
