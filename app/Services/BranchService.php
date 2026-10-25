<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\BranchData;
use App\Models\Branch;
use App\Models\BranchPrice;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Repositories\Contracts\BranchRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchService
{
    /** Roles that count as running a branch on its setup checklist. */
    public const array BRANCH_MANAGER_ROLES = ['admin', 'manager'];

    public function __construct(
        private readonly BranchRepositoryInterface $branches,
    ) {}

    public function list(int $perPage): LengthAwarePaginator
    {
        return $this->branches->paginate($perPage);
    }

    /**
     * Full branch list backing the Zone create/edit branch picker. Branches
     * change even less often than zones, so this mirrors
     * ZoneService::all()'s hour-long cache, explicitly invalidated on
     * create() below.
     *
     * @return Collection<int, Branch>
     */
    public function all(): Collection
    {
        return Cache::remember('branches:all', now()->addHour(), fn (): Collection => $this->branches->all());
    }

    public function create(BranchData $data): Branch
    {
        $branch = $this->branches->create($data);

        Cache::forget('branches:all');

        return $branch;
    }

    public function update(Branch $branch, BranchData $data): Branch
    {
        $branch = $this->branches->update($branch, $data);

        Cache::forget('branches:all');

        return $branch;
    }

    /**
     * zones.branch_id has restrictOnDelete() (see
     * 2026_08_24_160010_add_branch_id_to_zones_table.php) — the database
     * refuses to delete a branch that still has zones assigned to it. That
     * refusal surfaces here as a QueryException; translate it into a
     * friendly ValidationException instead of letting a raw SQL error reach
     * the controller/user. Postgres reports this as SQLSTATE 23001
     * (restrict_violation, what RESTRICT actually raises) — 23503
     * (foreign_key_violation, the more commonly-cited FK error code, e.g.
     * on INSERT/UPDATE referencing a missing row) is also checked in case
     * the constraint action ever changes.
     *
     * The delete is wrapped in DB::transaction() so a violation here rolls
     * back to a savepoint rather than poisoning the whole request's
     * transaction — Postgres refuses every further statement on a
     * transaction after an unhandled error ("current transaction is
     * aborted") until it's rolled back, which would otherwise break the
     * zones()->count() lookup below (and, in tests, the outer per-test
     * transaction).
     */
    public function delete(Branch $branch): void
    {
        if ($branch->is_headquarters) {
            throw ValidationException::withMessages([
                'branch' => ['Headquarters cannot be deleted.'],
            ]);
        }

        try {
            DB::transaction(fn () => $this->branches->delete($branch));
        } catch (QueryException $e) {
            if (! $this->isForeignKeyViolation($e)) {
                throw $e;
            }

            $zoneCount = $branch->zones()->count();

            throw ValidationException::withMessages([
                'branch' => ["Cannot delete this branch — {$zoneCount} zone(s) are still assigned to it. Reassign or remove them first."],
            ]);
        }

        Cache::forget('branches:all');
    }

    /**
     * The branch setup page's checklist, each step judged from real data
     * (never a tick for having merely visited a page):
     *
     *  - zones: the branch has at least one zone.
     *  - manager: an active user with the admin or manager role is fenced to
     *    this branch. Headquarters also counts its unfenced office staff,
     *    who act for headquarters.
     *  - settings: the branch has a short code or any own contact detail,
     *    MoMo number or fee, or any price change on record. Not applicable
     *    to headquarters, whose values are the company settings.
     *
     * @return array{
     *     zones: array{done: bool, count: int, names: list<string>},
     *     manager: array{done: bool, names: list<string>},
     *     settings: array{applicable: bool, done: bool}
     * }
     */
    public function setupChecklist(Branch $branch): array
    {
        // Explicitly this branch's zones: the page is for whoever manages
        // branches (an HQ-fenced admin included), not narrowed by their fence.
        $zoneNames = Zone::query()->withoutGlobalScope(BranchScope::class)->where('branch_id', $branch->id)->orderBy('name')->pluck('name');

        $managerUserIds = TenantUser::query()
            ->whereIn('role', self::BRANCH_MANAGER_ROLES)
            ->where(fn ($query) => $query->where('branch_id', $branch->id)
                ->when($branch->is_headquarters, fn ($query) => $query->orWhereNull('branch_id')))
            ->pluck('user_id');

        $managerNames = $managerUserIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $managerUserIds)->where('status', 'active')->orderBy('name')->pluck('name');

        $settingsDone = ! $branch->is_headquarters
            && (filled($branch->code) || $branch->overrides() !== [] || BranchPrice::query()->where('branch_id', $branch->id)->exists());

        return [
            'zones' => ['done' => $zoneNames->isNotEmpty(), 'count' => $zoneNames->count(), 'names' => $zoneNames->values()->all()],
            'manager' => ['done' => $managerNames->isNotEmpty(), 'names' => $managerNames->values()->all()],
            'settings' => ['applicable' => ! $branch->is_headquarters, 'done' => $settingsDone],
        ];
    }

    private function isForeignKeyViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23001', '23503'], true);
    }
}
