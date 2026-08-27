<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantUserRequest;
use App\Http\Requests\UpdateTenantUserRequest;
use App\Models\Branch;
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
 * Settings — Users & Roles (web-admin-spec.md section 3.14). Bridges the
 * central `users` table (pgsql connection, via App\Models\User) and the
 * tenant-scoped `tenant_users` table (tenant connection, via
 * App\Models\TenantUser) — see store()'s doc block for why user creation
 * can't be a single DB::transaction() wrapping both.
 *
 * Also where a tenant owner assigns a staff member's branch fence
 * (branches-and-locations.md section 4) — deliberately inline here rather
 * than a dedicated Service, matching this controller's existing style of
 * manipulating TenantUser directly rather than through a Service layer.
 */
class SettingsUserController extends Controller
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

        $branches = $this->branches->all();

        return Inertia::render('Settings/Users', [
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
                // PaymentPolicy::create()'s doc comment. Always sent (not
                // conditional on role) so Settings/Users.tsx can render a
                // checkbox purely from this one field.
                'can_record_payments' => $tenantUser->can_record_payments,
                // Investor tier grant — see ReportPolicy::view()'s doc
                // comment. Unlike can_record_payments, meaningful on any
                // role, so Settings/Users.tsx renders this checkbox
                // unconditionally.
                'is_investor' => $tenantUser->is_investor,
            ])->values(),
            // Only rendered by Settings/Users.tsx when there are 2+
            // branches (mirrors Zones/Create.tsx's branch picker — a
            // single-branch tenant never sees this control at all, see
            // branches-and-locations.md section 4's UI note).
            'branches' => $branches->map(fn (Branch $branch): array => [
                'uuid' => $branch->uuid,
                'name' => $branch->name,
            ])->values(),
        ]);
    }

    /**
     * Creates the central User row and the tenant-scoped TenantUser row
     * that links it to the current tenant. These two inserts are on
     * DIFFERENT database connections (central `pgsql` vs the tenant
     * connection), so they cannot be wrapped in a single DB::transaction().
     * We run two separate transactions instead: central user first, then
     * tenant_users. If the second insert fails, the central user row is
     * left orphaned (no tenant membership) — for a low-volume admin action
     * this is an acceptable tradeoff. We deliberately do NOT swallow that
     * failure; it propagates as a 500 so the admin notices and can retry
     * (StoreTenantUserRequest's unique:pgsql.users,email rule means a retry
     * against the same email will surface a clear validation error instead
     * of a duplicate).
     */
    public function store(StoreTenantUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $branchId = $this->resolveBranchId($data['branch_uuid'] ?? null);

        $user = DB::connection('pgsql')->transaction(fn (): User => User::query()->create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            // The 'password' attribute is cast to 'hashed' on the User
            // model, so Eloquent hashes this automatically on save.
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

        return redirect()->route('settings.users.index')->with('success', 'User created.');
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

        // Investor grant/revoke audit trail — mirrors is_landlord's
        // granted_by/granted_at pair on the central `users` table (see
        // that migration's doc comment), relocated here since investor
        // authority is tenant-scoped. Stamped from the CURRENT central
        // user performing this request (already authorized as super/admin
        // by UpdateTenantUserRequest::authorize()), not the target row.
        if (array_key_exists('is_investor', $data)) {
            $data['investor_granted_by'] = $data['is_investor'] ? $request->user()->id : null;
            $data['investor_granted_at'] = $data['is_investor'] ? now() : null;
        }

        $tenantUser->update($data);

        return redirect()->route('settings.users.index')->with('success', 'Saved.');
    }

    /**
     * Resolves an external-facing branch_uuid to an internal branch_id, or
     * null when no branch was picked (the "unrestricted" default) —
     * mirrors App\Services\CustomerService::resolveZoneId()'s existing
     * uuid-resolution pattern elsewhere in this codebase.
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

        return redirect()->route('settings.users.index')->with('success', 'User deactivated.');
    }
}
