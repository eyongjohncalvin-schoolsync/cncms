<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Permission;
use App\Models\Branch;
use App\Models\Complaint;
use App\Models\Role;
use App\Models\Scopes\BranchScope;
use App\Models\TechnicianZone;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Support\BranchLookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Technicians: whoever's role may resolve complaints (complaints.resolve —
 * a "Fiber Technician" custom role as much as a manager), the owner aside.
 * Their usual zones (App\Models\TechnicianZone) are optional and only a
 * routing hint: none means "works anywhere"; any complaint can still go to
 * anyone. Complaint assignment suggests the complaint's zone's technicians
 * first, each with how many open complaints they already hold.
 */
class TechnicianService
{
    /**
     * Active technicians who work in the branch — fenced to it, or not
     * fenced to any branch — each with their zones in it and open
     * complaints. Three queries whatever the size.
     *
     * @return Collection<int, array{uuid: string, user_id: int, name: string, role: string, zone_ids: list<int>, open: int}>
     */
    public function forBranch(Branch $branch): Collection
    {
        $tenantUsers = TenantUser::query()
            ->where('tenant_id', (string) tenant()?->getTenantKey())
            ->whereIn('role', $this->technicianRoles())
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->with('user')
            ->get()
            ->filter(fn (TenantUser $tenantUser): bool => $tenantUser->user?->status === 'active');

        $userIds = $tenantUsers->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all();
        $zones = TechnicianZone::query()->withoutGlobalScope(BranchScope::class)
            ->whereIn('user_id', $userIds)->where('branch_id', $branch->id)
            ->get(['user_id', 'zone_id'])->groupBy('user_id');
        $open = $this->openCounts($userIds);
        $labels = Role::query()->pluck('label', 'name');

        return $tenantUsers->map(fn (TenantUser $tenantUser): array => [
            'uuid' => (string) $tenantUser->user->uuid,
            'user_id' => (int) $tenantUser->user_id,
            'name' => (string) $tenantUser->user->name,
            'role' => (string) ($labels[$tenantUser->role] ?? $tenantUser->role),
            'zone_ids' => $zones->get($tenantUser->user_id, collect())->pluck('zone_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'open' => $open[(int) $tenantUser->user_id] ?? 0,
        ])->sortBy('name')->values();
    }

    /**
     * Who to suggest for a complaint, best first: technicians covering its
     * zone, then those who work anywhere, then the rest — and within each,
     * the fewest open complaints first.
     *
     * @return list<array{uuid: string, name: string, role: string, open: int, reason: string}>
     */
    public function suggestionsFor(Complaint $complaint): array
    {
        $branch = Branch::allMemoized()->get($complaint->branch_id) ?? Branch::allMemoized()->get(Branch::headquartersId());

        if ($branch === null) {
            return [];
        }

        $rank = ['covers' => 0, 'anywhere' => 1, 'elsewhere' => 2];

        return $this->forBranch($branch)
            ->map(function (array $technician) use ($complaint): array {
                $reason = match (true) {
                    $complaint->zone_id !== null && in_array((int) $complaint->zone_id, $technician['zone_ids'], true) => 'covers',
                    $technician['zone_ids'] === [] => 'anywhere',
                    default => 'elsewhere',
                };

                return ['uuid' => $technician['uuid'], 'name' => $technician['name'], 'role' => $technician['role'], 'open' => $technician['open'], 'reason' => $reason];
            })
            ->sort(fn (array $a, array $b): int => [$rank[$a['reason']], $a['open'], $a['name']] <=> [$rank[$b['reason']], $b['open'], $b['name']])
            ->values()
            ->all();
    }

    /** Whether this user may be given a complaint of this branch. */
    public function canBeAssigned(User $user, ?int $branchId): bool
    {
        return TenantUser::query()
            ->where('tenant_id', (string) tenant()?->getTenantKey())
            ->where('user_id', $user->id)
            ->whereIn('role', $this->technicianRoles())
            ->when($branchId !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->exists() && $user->status === 'active';
    }

    /**
     * The technician's usual zones in this branch become exactly these
     * (none = works anywhere). Their zones in other branches are left alone.
     *
     * @param  list<string>  $zoneUuids
     */
    public function setZones(User $technician, Branch $branch, array $zoneUuids, User $actor): void
    {
        if (! $this->canBeAssigned($technician, $branch->id)) {
            throw ValidationException::withMessages(['user_uuid' => "{$technician->name} doesn't resolve complaints in this branch."]);
        }

        $zones = Zone::query()->withoutGlobalScope(BranchScope::class)->whereIn('uuid', array_values(array_unique($zoneUuids)))->get(['id', 'branch_id']);

        if ($zones->count() !== count(array_unique($zoneUuids)) || $zones->contains(fn (Zone $zone): bool => $zone->branch_id !== $branch->id)) {
            throw ValidationException::withMessages(['zone_uuids' => 'Choose zones of this branch.']);
        }

        // New technician_zones rows take their zone's branch from memory, not a query each.
        BranchLookup::remember($zones);

        DB::transaction(function () use ($technician, $branch, $zones, $actor): void {
            TechnicianZone::query()->withoutGlobalScope(BranchScope::class)
                ->where('user_id', $technician->id)->where('branch_id', $branch->id)
                ->whereNotIn('zone_id', $zones->pluck('id'))
                ->delete();

            $existing = TechnicianZone::query()->withoutGlobalScope(BranchScope::class)
                ->where('user_id', $technician->id)->whereIn('zone_id', $zones->pluck('id'))->pluck('zone_id')->all();

            foreach ($zones->whereNotIn('id', $existing) as $zone) {
                TechnicianZone::query()->create(['user_id' => $technician->id, 'zone_id' => $zone->id, 'assigned_by' => $actor->id]);
            }
        });
    }

    /**
     * Open (not resolved) complaints each user holds, in every branch.
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function openCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Complaint::query()->withoutGlobalScope(BranchScope::class)
            ->whereIn('assigned_to', $userIds)->where('status', '!=', 'resolved')
            ->groupBy('assigned_to')->selectRaw('assigned_to, count(*) as open')
            ->pluck('open', 'assigned_to')
            ->mapWithKeys(fn ($count, $userId): array => [(int) $userId => (int) $count])
            ->all();
    }

    /**
     * Roles that resolve complaints, the owner's (super) aside. Before a
     * tenant's roles are seeded, the seeded defaults' (admin, manager).
     *
     * @return list<string>
     */
    private function technicianRoles(): array
    {
        $roles = Role::query()->where('is_super', false)
            ->whereHas('permissions', fn ($query) => $query->where('permission', Permission::ComplaintsResolve->value))
            ->pluck('name')->all();

        return $roles !== [] || Role::query()->exists() ? $roles : ['admin', 'manager'];
    }
}
