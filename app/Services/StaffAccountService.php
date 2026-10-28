<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\CacheVersion;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

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
