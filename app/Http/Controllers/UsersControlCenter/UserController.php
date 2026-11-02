<?php

declare(strict_types=1);

namespace App\Http\Controllers\UsersControlCenter;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantUserRequest;
use App\Http\Requests\UpdateTenantUserRequest;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\Role;
use App\Models\SalaryGrade;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\User;
use App\Repositories\Contracts\BranchRepositoryInterface;
use App\Services\SalaryService;
use App\Services\StaffAccountService;
use App\Services\StaffRemovalService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
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
        private readonly SalaryService $salaries,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', TenantUser::class);

        $tenantUsers = TenantUser::query()
            ->where('tenant_id', $this->context->tenantUser->tenant_id)
            // Branches v2 (D7): a fenced manager sees their branch's staff —
            // fenced to it, or agents of its zones (Agent is branch-scoped).
            ->when($this->context->isFenced(), fn ($query) => $query->where(fn ($inner) => $inner
                ->where('branch_id', $this->context->branchId)
                ->orWhere(fn ($agents) => $agents->where('role', 'agent')->whereIn('user_id', Agent::query()->whereNotNull('user_id')->pluck('user_id')))))
            ->with(['user', 'branch'])
            ->get();

        // Agent-role accounts that can't work yet: no agent profile, or one
        // that covers no zone (they would log in to an empty app). Two queries.
        $agentUserIds = $tenantUsers->where('role', 'agent')->pluck('user_id')->all();
        $agentProfiles = Agent::query()->withoutGlobalScope(BranchScope::class)->whereIn('user_id', $agentUserIds)->withCount('coverage')->get(['id', 'user_id'])->keyBy('user_id');

        // Branches v2 D8: salaries for whoever may set them — two queries for the whole list.
        $canSetSalaries = $this->context->can('salaries.manage');
        $salaries = $canSetSalaries ? $this->salaries->summaryFor($tenantUsers->pluck('user_id')->map(fn ($id): int => (int) $id)->all()) : [];

        // Delete is offered only for accounts with no history — one query for the list.
        $withHistory = app(StaffRemovalService::class)->usersWithHistory($tenantUsers->pluck('user_id')->map(fn ($id): int => (int) $id)->all());

        // Roles limited to their own zones (a role setting since 2026-11-02) — one query for the list.
        $zoneLimitedRoles = Role::query()->where('zone_limited', true)->pluck('name')->all();
        // Roles that record payments already — the per-person grant is offered to everyone else.
        $recordingRoles = Role::query()->where('is_super', true)
            ->orWhereHas('permissions', fn ($query) => $query->where('permission', 'payments.create'))
            ->pluck('name')->all();

        return Inertia::render('UsersControlCenter/Users', [
            'can_set_salaries' => $canSetSalaries,
            'salary_grades' => $canSetSalaries
                ? SalaryGrade::query()->where('is_active', true)->orderBy('min_amount')->get(['uuid', 'name', 'min_amount', 'max_amount'])
                    ->map(fn (SalaryGrade $grade): array => ['uuid' => $grade->uuid, 'name' => $grade->name, 'min_amount' => (string) $grade->min_amount, 'max_amount' => (string) $grade->max_amount])
                    ->values()->all()
                : [],
            'users' => $tenantUsers->map(fn (TenantUser $tenantUser): array => [
                'salary' => $salaries[(int) $tenantUser->user_id] ?? null,
                'agent_setup' => ! in_array($tenantUser->role, $zoneLimitedRoles, true) ? null : match (true) {
                    // The agent record comes with the account (StaffAccountService); what can be missing is zones.
                    ! $agentProfiles->has($tenantUser->user_id), $agentProfiles[$tenantUser->user_id]->coverage_count === 0 => 'no_zones',
                    default => null,
                },
                'id' => $tenantUser->id,
                'role' => $tenantUser->role,
                'job_title' => $tenantUser->job_title,
                'name' => $tenantUser->user->name,
                'username' => $tenantUser->user->username,
                'email' => $tenantUser->user->email,
                'status' => $tenantUser->user->status,
                'can_delete' => ! isset($withHistory[(int) $tenantUser->user_id])
                    && $tenantUser->user_id !== request()->user()->id
                    && ! $tenantUser->user->is_landlord,
                'branch_uuid' => $tenantUser->branch?->uuid,
                'branch_name' => $tenantUser->branch?->name,
                // Only meaningful for role === 'worker' — see
                // PaymentPolicy::create()'s doc comment. Always sent so the
                // page can render a checkbox purely from this one field.
                'can_record_payments' => $tenantUser->can_record_payments,
                'role_records_payments' => in_array($tenantUser->role, $recordingRoles, true),
                'zone_limited' => in_array($tenantUser->role, $zoneLimitedRoles, true),
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
            // Branches v2 (D7): a fenced manager hires into their branch and
            // never moves staff between branches or grants investor status.
            'fenced' => $this->context->isFenced(),
        ]);
    }

    /**
     * A new staff account (StaffAccountService::create() — the central user
     * and its membership; a field agent's agent record with it).
     */
    public function store(StoreTenantUserRequest $request, StaffAccountService $accounts): RedirectResponse
    {
        $data = $request->validated();
        // Branches v2 (D7): a fenced manager hires into their own branch.
        $branchId = $this->context->isFenced() ? $this->context->branchId : $this->resolveBranchId($data['branch_uuid'] ?? null);

        $accounts->create($data, $branchId, $request->user());

        return redirect()->route('users.index')->with('success', $data['role'] === 'agent'
            ? 'Field agent created — give them zones on the Agents page.'
            : 'User created.');
    }

    /**
     * The rules (branch moves, the worker flag, investor stamps, the agent
     * record, the assignment history) live in StaffAccountService::update(),
     * shared with the phone's staff screen.
     */
    public function update(UpdateTenantUserRequest $request, TenantUser $tenantUser, StaffAccountService $accounts): RedirectResponse
    {
        $data = $request->validated();

        if (array_key_exists('branch_uuid', $data)) {
            $data['branch_id'] = $this->resolveBranchId($data['branch_uuid']);
            unset($data['branch_uuid']);
        }

        $accounts->update($tenantUser, $data, $request->user());

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

    /**
     * Makes the user passive and signs them out of every browser and phone
     * at once (AccountSecurityService::deactivate()), not just at their next
     * sign-in.
     */
    public function deactivate(TenantUser $tenantUser, StaffAccountService $accounts): RedirectResponse
    {
        $this->authorize('deactivate', $tenantUser);

        $accounts->deactivate($tenantUser);

        return redirect()->route('users.index')->with('success', 'User deactivated.');
    }

    /**
     * Lets a deactivated user sign in again (their old sessions stay ended).
     */
    public function activate(TenantUser $tenantUser, StaffAccountService $accounts): RedirectResponse
    {
        $this->authorize('activate', $tenantUser);

        $accounts->activate($tenantUser);

        return redirect()->route('users.index')->with('success', 'User reactivated.');
    }

    /**
     * Deletes an account that has no history; anyone else can only be
     * deactivated (StaffRemovalService).
     */
    public function destroy(TenantUser $tenantUser, StaffRemovalService $removal): RedirectResponse
    {
        $this->authorize('delete', $tenantUser);

        $name = $tenantUser->user->name;
        $removal->delete($tenantUser, request()->user());

        return redirect()->route('users.index')->with('success', "{$name} deleted.");
    }
}
