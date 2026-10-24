<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\SalaryGrade;
use App\Models\Scopes\BranchScope;
use App\Models\StaffSalary;
use App\Models\User;
use App\Support\BusinessTimezone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Salaries (branches v2 §4.5, D8): headquarters' grades set the range; a
 * salary inside its grade's range is approved at once, outside it waits for
 * headquarters — the previous salary stays in force meanwhile. Every decision
 * is kept. An agent's approved salary is mirrored on agents.salary (the
 * mobile app reads it).
 */
class SalaryService
{
    /**
     * Sets $userId's salary. Returns the decision row: approved, or
     * pending_hq when outside the grade's range (or no grade is given while
     * grades exist — a salary must sit on the scale to need no approval).
     */
    public function set(int $userId, ?int $branchId, string $amount, ?SalaryGrade $grade, ?string $reason, User $actor): StaffSalary
    {
        $amount = bcadd($amount, '0', 2);

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'A salary must be more than zero.']);
        }

        $inRange = $grade !== null ? $grade->covers($amount) : ! SalaryGrade::query()->where('is_active', true)->exists();

        return DB::transaction(function () use ($userId, $branchId, $amount, $grade, $reason, $actor, $inRange): StaffSalary {
            // A newer proposal replaces one still waiting for headquarters.
            StaffSalary::query()->where('user_id', $userId)->where('status', StaffSalary::STATUS_PENDING)->lockForUpdate()
                ->update(['status' => StaffSalary::STATUS_REJECTED, 'decision_note' => 'Replaced by a newer salary', 'decided_at' => now()]);

            $salary = StaffSalary::query()->create([
                'user_id' => $userId,
                'branch_id' => $branchId,
                'salary_grade_id' => $grade?->id,
                'amount' => $amount,
                'effective_from' => BusinessTimezone::today(),
                'status' => $inRange ? StaffSalary::STATUS_APPROVED : StaffSalary::STATUS_PENDING,
                'reason' => $reason,
                'set_by' => $actor->id,
                'decided_by' => $inRange ? $actor->id : null,
                'decided_at' => $inRange ? now() : null,
            ]);

            if ($inRange) {
                $this->mirrorOnAgent($userId, $amount);
            }

            return $salary;
        });
    }

    /** The grade of a person's current (approved) salary, if any. */
    public function currentGrade(int $userId): ?SalaryGrade
    {
        $gradeId = StaffSalary::query()->where('user_id', $userId)->where('status', StaffSalary::STATUS_APPROVED)->latest('id')->value('salary_grade_id');

        return $gradeId !== null ? SalaryGrade::query()->find($gradeId) : null;
    }

    /** Headquarters approves (or rejects) a salary outside its grade's range. */
    public function decide(StaffSalary $salary, bool $approve, ?string $note, User $actor): StaffSalary
    {
        return DB::transaction(function () use ($salary, $approve, $note, $actor): StaffSalary {
            $locked = StaffSalary::query()->whereKey($salary->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StaffSalary::STATUS_PENDING) {
                throw ValidationException::withMessages(['salary' => 'This salary was already decided.']);
            }

            $locked->update([
                'status' => $approve ? StaffSalary::STATUS_APPROVED : StaffSalary::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'effective_from' => $approve ? BusinessTimezone::today() : $locked->effective_from,
            ]);

            if ($approve) {
                $this->mirrorOnAgent((int) $locked->user_id, (string) $locked->amount);
            }

            return $locked;
        });
    }

    /**
     * Each person's current (approved) salary and any salary waiting for
     * headquarters — two queries for any number of people.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{current: ?string, grade: ?string, pending: ?array{uuid: string, amount: string}}>
     */
    public function summaryFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $current = StaffSalary::query()
            ->selectRaw('DISTINCT ON (user_id) user_id, amount, salary_grade_id')
            ->whereIn('user_id', $userIds)
            ->where('status', StaffSalary::STATUS_APPROVED)
            ->orderByRaw('user_id, id DESC')
            ->with('grade:id,name')
            ->get()
            ->keyBy('user_id');

        $pending = StaffSalary::query()->whereIn('user_id', $userIds)->where('status', StaffSalary::STATUS_PENDING)->get()->keyBy('user_id');

        return collect($userIds)->mapWithKeys(fn (int $userId): array => [$userId => [
            'current' => isset($current[$userId]) ? (string) $current[$userId]->amount : null,
            'grade' => isset($current[$userId]) ? $current[$userId]->grade?->name : null,
            'pending' => isset($pending[$userId]) ? ['uuid' => $pending[$userId]->uuid, 'amount' => (string) $pending[$userId]->amount] : null,
        ]])->all();
    }

    /**
     * Salaries waiting for headquarters, oldest first.
     *
     * @return Collection<int, StaffSalary>
     */
    public function pending(): Collection
    {
        return StaffSalary::query()
            ->where('status', StaffSalary::STATUS_PENDING)
            ->with(['grade:id,name,min_amount,max_amount', 'branch:id,name', 'user:id,name', 'setBy:id,name'])
            ->orderBy('id')
            ->get();
    }

    private function mirrorOnAgent(int $userId, string $amount): void
    {
        Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $userId)->update(['salary' => $amount]);
    }
}
