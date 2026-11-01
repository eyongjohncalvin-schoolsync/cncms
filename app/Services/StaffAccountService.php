<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\Branch;
use App\Models\CashTransfer;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\CacheVersion;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A staff account: the central User and its membership of this tenant —
 * and, for a field agent, their agent record (owner, 2026-10-28: a field
 * agent IS a user; the Agents page only manages their zones). The Users page
 * and the Agents page's "Create login" for an old agent without one both go
 * through create(); every role change, deactivation and reactivation keeps
 * the agent record in step (syncAgent()).
 */
class StaffAccountService
{
    public function __construct(
        private readonly StaffAssignmentService $assignments,
    ) {}

    /**
     * The central User row and the tenant_users row live on DIFFERENT
     * database connections (central `pgsql` vs the tenant connection), so
     * they can't share one transaction: central user first, then the
     * membership. If the second insert fails the central user is left with no
     * membership — acceptable for a low-volume admin action; the failure is
     * not swallowed, so the admin sees it and can retry.
     *
     * @param  array{name: string, username: string, email: string, password: string, role: string, job_title?: ?string}  $data
     * @param  ?Agent  $existingAgent  an old agent record without a login, to link instead of creating a new one
     */
    public function create(array $data, ?int $branchId, User $actor, ?Agent $existingAgent = null): TenantUser
    {
        $user = DB::connection('pgsql')->transaction(fn (): User => User::query()->create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            // Cast to 'hashed' on the User model.
            'password' => $data['password'],
            'status' => 'active',
        ]));

        return DB::transaction(function () use ($user, $data, $branchId, $actor, $existingAgent): TenantUser {
            $tenantUser = TenantUser::query()->create([
                'user_id' => $user->id,
                'tenant_id' => app(TenantContext::class)->tenantUser->tenant_id,
                'role' => $data['role'],
                'job_title' => $data['job_title'] ?? null,
                'branch_id' => $branchId,
            ]);

            $existingAgent?->forceFill(['user_id' => $user->id])->save();
            $this->syncAgent($tenantUser->setRelation('user', $user));
            $this->assignments->record($user->id, $existingAgent?->branch_id ?? $branchId, $data['role'], $actor, 'Hired');

            return $tenantUser;
        });
    }

    /**
     * Saves changes to a membership — the Users Control Center and the
     * phone's staff screen (mobile wave 5) both come through here, so a role
     * change has the same side effects wherever it is made. The caller has
     * validated $data (UpdateTenantUserRequest) and turned a branch uuid
     * into `branch_id`.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(TenantUser $tenantUser, array $data, User $actor): void
    {
        $context = app(TenantContext::class);

        // Branches v2 (D7): only headquarters moves staff between branches or
        // grants investor status — the request already refuses these from a
        // fenced manager; this is the second lock.
        if ($context->isFenced()) {
            unset($data['branch_id'], $data['is_investor']);
        }

        if (array_key_exists('branch_id', $data) && $data['branch_id'] !== $tenantUser->branch_id) {
            $cashBook = app(CashBookService::class);

            // Branches v2: a cashier's till belongs to their branch — money in it
            // must be handed over before they move to another branch.
            $till = $cashBook->activeTillOf($tenantUser->user);

            if ($till !== null && bccomp($cashBook->balance($till), '0', 2) !== 0) {
                throw ValidationException::withMessages(['branch_uuid' => ['Their till still holds money — hand it over (and close the day) before moving them to another branch.']]);
            }

            // Wave 3: nor while money they sent or must accept is in transit.
            if (CashTransfer::query()->withoutGlobalScope(BranchScope::class)->inTransit()
                ->where(fn ($query) => $query->where('sent_by', $tenantUser->user_id)->orWhere('to_user_id', $tenantUser->user_id))->exists()) {
                throw ValidationException::withMessages(['branch_uuid' => ['Money they sent or must accept is still in transit — it is accepted, refused or recalled first.']]);
            }
        }

        if ($context->isFenced()) {
            // Pin whoever a fenced manager edits to that branch: an agent's fence
            // comes from their zone (tenant_users.branch_id is often null), so a
            // role change away from agent would otherwise leave them unfenced.
            $data['branch_id'] = $context->branchId;
        }

        // Defensive: a role change away from 'worker' also clears the
        // payment-recording flag, so it can't silently reactivate later if
        // this same person is ever demoted back to 'worker'. The flag is
        // meaningless for every other role — see PaymentPolicy::create().
        if (array_key_exists('role', $data) && $data['role'] !== 'worker') {
            $data['can_record_payments'] = false;
        }

        // Investor grant/revoke audit trail — stamped from the person making
        // the change, not the target row.
        if (array_key_exists('is_investor', $data)) {
            $data['investor_granted_by'] = $data['is_investor'] ? $actor->id : null;
            $data['investor_granted_at'] = $data['is_investor'] ? now() : null;
        }

        $tenantUser->update($data);

        // Becoming a field agent creates (or revives) their agent record; leaving the role retires it.
        if ($tenantUser->wasChanged('role')) {
            $this->syncAgent($tenantUser->load('user'));
        }

        // Branches v2 (D7): a transfer or a new role opens the next assignment.
        if ($tenantUser->wasChanged(['branch_id', 'role'])) {
            $agentBranchId = $tenantUser->role === 'agent'
                ? Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $tenantUser->user_id)->with(['zone' => fn ($query) => $query->withoutGlobalScope(BranchScope::class)])->first()?->zone?->branch_id
                : null;

            $this->assignments->record($tenantUser->user_id, $agentBranchId ?? $tenantUser->branch_id, $tenantUser->role, $actor);
        }
    }

    /**
     * Makes the user passive and signs them out of every browser and phone
     * at once (AccountSecurityService::deactivate()) — never while they
     * hold the company's money (owner, 2026-10-29).
     */
    public function deactivate(TenantUser $tenantUser): void
    {
        $held = app(CashBookService::class)->moneyHeldBy($tenantUser->user);

        if ($held !== []) {
            throw ValidationException::withMessages(['user' => "{$tenantUser->user->name} cannot be deactivated yet: ".implode('; ', $held).'.']);
        }

        app(AccountSecurityService::class)->deactivate($tenantUser->user);
        // A field agent stops covering their zones.
        $this->syncAgent($tenantUser->load('user'));
    }

    /** Lets a deactivated user sign in again (their old sessions stay ended). */
    public function activate(TenantUser $tenantUser): void
    {
        $tenantUser->user->update(['status' => 'active']);
        // A field agent is active again — with no zones until given some on the Agents page.
        $this->syncAgent($tenantUser->load('user'));
    }

    /**
     * Keeps a user's agent record in step with their account: a field agent
     * (role `agent`, account active) has an active record — created on first
     * need, named after the user — and anyone else has none active. Retiring
     * a record also ends its zone coverage, so a zone never counts as covered
     * by someone who no longer works it; zones are given again on the Agents
     * page.
     */
    public function syncAgent(TenantUser $tenantUser): void
    {
        $user = $tenantUser->user;
        $agent = Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $user->id)->first();
        $isFieldAgent = $tenantUser->role === 'agent' && $user->status === 'active';

        if ($isFieldAgent) {
            if ($agent === null) {
                // No zone yet: the record takes the user's branch (headquarters when unrestricted).
                $agent = (new Agent)->forceFill(['branch_id' => $tenantUser->branch_id ?? Branch::headquartersId()]);
            }

            $agent->fill(['name' => mb_substr($user->name, 0, 50), 'email' => $user->email !== null ? mb_substr($user->email, 0, 50) : null, 'status' => 'active'])
                ->forceFill(['user_id' => $user->id])
                ->save();

            return;
        }

        if ($agent !== null && $agent->status === 'active') {
            $agent->coverage()->withoutGlobalScope(BranchScope::class)->delete();
            $agent->update(['status' => 'inactive']);
            // The coverage delete is a bulk write: no model events.
            CacheVersion::bump(CacheVersion::AGENTS);
        }
    }
}
