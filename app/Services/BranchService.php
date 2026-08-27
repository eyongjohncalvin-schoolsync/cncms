<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\BranchData;
use App\Models\Branch;
use App\Repositories\Contracts\BranchRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchService
{
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

    private function isForeignKeyViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23001', '23503'], true);
    }
}
