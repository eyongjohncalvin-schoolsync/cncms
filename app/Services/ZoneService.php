<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ZoneData;
use App\Models\Zone;
use App\Repositories\Contracts\BranchRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ZoneService
{
    /** Most zones one bulk-delete request may touch. */
    public const int BULK_DELETE_LIMIT = 500;

    public function __construct(
        private readonly ZoneRepositoryInterface $zones,
        private readonly BranchRepositoryInterface $branches,
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->zones->paginate($filters, $perPage);
    }

    /**
     * Full zone list backing every zone dropdown/select input across
     * Customers/Agents/Payments/Manuscripts filters. Zones barely ever
     * change (29 of them), so this is cached for an hour and explicitly
     * invalidated on create/update/delete below.
     *
     * @return Collection<int, Zone>
     */
    public function all(): Collection
    {
        return Cache::remember('zones:all', now()->addHour(), fn (): Collection => $this->zones->all());
    }

    public function findOrFail(string $uuid): Zone
    {
        $zone = $this->zones->findByUuid($uuid);

        if (! $zone) {
            throw new ModelNotFoundException("Zone [{$uuid}] not found.");
        }

        return $zone;
    }

    public function create(ZoneData $data): Zone
    {
        return $this->createInBranch($this->resolveBranchId($data->branchUuid), $data)->load('branch');
    }

    /**
     * create()'s write path for a caller that has already resolved the
     * branch through resolveBranchId() (the spreadsheet import, which
     * resolves it once per file) — and that doesn't need `branch` loaded.
     */
    public function createInBranch(int $branchId, ZoneData $data): Zone
    {
        $zone = $this->zones->create($branchId, $data);

        Cache::forget('zones:all');

        return $zone;
    }

    public function update(Zone $zone, ZoneData $data): Zone
    {
        $branchId = $data->branchUuid !== null ? $this->resolveBranchId($data->branchUuid) : null;

        $zone = $this->zones->update($zone, $data, $branchId);

        Cache::forget('zones:all');

        return $zone->load('branch');
    }

    /**
     * customers.zone_id and agents.zone_id are both restrictOnDelete() —
     * deleting a zone still referenced by either raises a raw Postgres
     * RESTRICT-violation QueryException (SQLSTATE 23001) if left unchecked,
     * surfacing an ugly, unfriendly error straight to the user. Checked
     * up front with plain counts rather than letting the exception bubble
     * and catching the SQLSTATE, since a friendly "N customers/agents are
     * still assigned" message needs the actual counts anyway.
     */
    public function delete(Zone $zone): void
    {
        $assignments = $this->describeAssignments($zone->customers()->count(), $zone->agents()->count());

        if ($assignments !== null) {
            throw ValidationException::withMessages([
                'zone' => ["Cannot delete this zone — {$assignments} still assigned to it. Reassign or remove them first."],
            ]);
        }

        $this->zones->delete($zone);

        Cache::forget('zones:all');
    }

    /**
     * Dry run for the Zones page's bulk delete modal: which of the selected
     * zones would be deleted and which kept (and why), without writing
     * anything. Selection is an explicit uuid list, or — when $uuids is
     * null — every zone matching $filters (the "select all N matching"
     * path), capped at BULK_DELETE_LIMIT; `truncated` says more matched.
     * The modal then commits the exact uuids returned here through
     * bulkDelete(), so what was confirmed on screen is what gets sent.
     *
     * @param  array<int, string>|null  $uuids
     * @param  array<string, mixed>  $filters
     * @return array{zones: array<int, array{uuid: string, name: string, town: string, customer_count: int, agent_count: int, kept_reason: string|null}>, truncated: bool}
     *
     * @throws ValidationException When an explicit uuid is unknown or outside the caller's branch.
     */
    public function previewBulkDelete(?array $uuids, array $filters): array
    {
        if ($uuids !== null) {
            $zones = $this->resolveForBulkDelete($uuids);
            $truncated = false;
        } else {
            $zones = $this->zones->forBulkAction($filters, null, self::BULK_DELETE_LIMIT + 1);
            $truncated = $zones->count() > self::BULK_DELETE_LIMIT;
            $zones = $zones->take(self::BULK_DELETE_LIMIT);
        }

        return [
            'zones' => $zones
                ->map(fn (Zone $zone): array => [
                    'uuid' => $zone->uuid,
                    'name' => $zone->name,
                    'town' => $zone->town,
                    'customer_count' => (int) $zone->customers_count,
                    'agent_count' => (int) $zone->agents_count,
                    'kept_reason' => $this->keptReason($zone),
                ])
                ->values()
                ->all(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Resolves an explicit bulk selection to Zone models through the same
     * branch fence as a single-zone lookup. All-or-nothing: if any uuid is
     * unknown, already gone, or belongs to a branch the caller can't see,
     * the whole request is refused before anything is deleted, rather than
     * silently acting on a different set than the one confirmed.
     *
     * @param  array<int, string>  $uuids
     * @return Collection<int, Zone>
     *
     * @throws ValidationException
     */
    public function resolveForBulkDelete(array $uuids): Collection
    {
        $zones = $this->zones->forBulkAction([], $uuids, self::BULK_DELETE_LIMIT);

        $missing = count($uuids) - $zones->count();

        if ($missing > 0) {
            throw ValidationException::withMessages([
                'zone_uuids' => [($missing === 1 ? '1 selected zone no longer exists' : "{$missing} selected zones no longer exist").' or is not available to you. Refresh the page and try again.'],
            ]);
        }

        return $zones;
    }

    /**
     * Deletes each zone through delete() so every existing rule (and the
     * Auditable observer's audit row) applies exactly as for a single
     * delete. Partial success: a zone delete() refuses is kept and
     * reported, never fatal for the rest of the batch.
     *
     * @param  Collection<int, Zone>  $zones
     * @return array{deleted: array<int, string>, kept: array<int, array{name: string, reason: string}>}
     */
    public function bulkDelete(Collection $zones): array
    {
        $deleted = [];
        $kept = [];

        foreach ($zones as $zone) {
            try {
                $this->delete($zone);
                $deleted[] = $zone->name;
            } catch (ValidationException $e) {
                $kept[] = [
                    'name' => $zone->name,
                    'reason' => $this->keptReason($zone) ?? collect($e->errors())->flatten()->implode(' '),
                ];
            }
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    /**
     * Short "why this zone stays" text from forBulkAction()'s preloaded
     * counts, or null when nothing blocks the delete.
     */
    private function keptReason(Zone $zone): ?string
    {
        $assignments = $this->describeAssignments((int) $zone->customers_count, (int) $zone->agents_count);

        return $assignments === null ? null : "{$assignments} still assigned";
    }

    /**
     * "3 customers and 1 agent" — or null when neither is assigned.
     */
    private function describeAssignments(int $customerCount, int $agentCount): ?string
    {
        $parts = [];

        if ($customerCount > 0) {
            $parts[] = $customerCount === 1 ? '1 customer' : "{$customerCount} customers";
        }

        if ($agentCount > 0) {
            $parts[] = $agentCount === 1 ? '1 agent' : "{$agentCount} agents";
        }

        return $parts === [] ? null : implode(' and ', $parts);
    }

    /**
     * Resolves the external-facing branch_uuid to an internal branch_id.
     * When no branch was explicitly picked (branch_uuid omitted), defaults
     * to the sole existing branch so zone creation doesn't suddenly demand
     * an extra decision when there's nothing to actually choose from yet —
     * see branches-and-locations.md section 8. Once more than one branch
     * exists, an explicit branch_uuid becomes required (zones.branch_id is
     * NOT NULL, so there is no other safe default at that point).
     */
    /**
     * @throws ValidationException
     */
    public function resolveBranchId(?string $branchUuid): int
    {
        if ($branchUuid !== null) {
            $branch = $this->branches->findByUuid($branchUuid);

            if (! $branch) {
                throw ValidationException::withMessages(['branch_uuid' => ['The selected branch does not exist.']]);
            }

            return $branch->id;
        }

        $branches = $this->branches->all();

        if ($branches->count() === 1) {
            return $branches->first()->id;
        }

        throw ValidationException::withMessages(['branch_uuid' => ['The branch_uuid field is required.']]);
    }
}
