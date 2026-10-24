<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StaffAssignment;
use App\Models\User;
use App\Support\BusinessTimezone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The staff assignment history (branches v2 §4.4, D7): opens a person's first
 * assignment when they are hired, and closes / opens the next one when their
 * branch or role changes — so "who worked where, when" is always answerable.
 */
class StaffAssignmentService
{
    /**
     * Records that $userId now works in $branchId (null = not fenced) as
     * $role, from today. A no-op when that is already their open assignment.
     */
    public function record(int $userId, ?int $branchId, string $role, ?User $changedBy = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($userId, $branchId, $role, $changedBy, $reason): void {
            $today = BusinessTimezone::today();
            $open = StaffAssignment::query()->where('user_id', $userId)->whereNull('ends_on')->lockForUpdate()->first();

            if ($open !== null && $open->branch_id === $branchId && $open->role === $role) {
                return;
            }

            if ($open !== null) {
                $open->update(['ends_on' => $today]);
            }

            StaffAssignment::query()->create([
                'user_id' => $userId,
                'branch_id' => $branchId,
                'role' => $role,
                'starts_on' => $today,
                'changed_by' => $changedBy?->id,
                'reason' => $reason,
            ]);
        });
    }

    /** @return Collection<int, StaffAssignment> newest first */
    public function historyFor(int $userId): Collection
    {
        return StaffAssignment::query()->where('user_id', $userId)->with('branch:id,name')->latest('starts_on')->latest('id')->get();
    }
}
