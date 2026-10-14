<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Locked months. After the owner locks a month nothing can be dated inside
 * it: a new expense dated there is refused, and money movements arriving
 * late (an offline phone syncing days later) are booked in the current
 * month instead, with their real time kept on the record.
 */
class PeriodLockService
{
    /** @var array<string, bool> */
    private array $locked = [];

    public function isLocked(string $period): bool
    {
        return $this->locked[$period] ??= AccountingPeriod::query()->where('period', $period)->where('status', 'locked')->exists();
    }

    /**
     * @throws ValidationException
     */
    public function assertOpen(Carbon|string $date, string $field = 'date'): void
    {
        $period = ($date instanceof Carbon ? $date : Carbon::parse($date))->format('Y-m');

        if ($this->isLocked($period)) {
            throw ValidationException::withMessages([$field => ["{$period} is closed. Nothing can be dated inside a closed month."]]);
        }
    }

    /**
     * The month a money movement is booked in: its own, unless that month
     * is locked, in which case the current month.
     */
    public function bookingPeriod(Carbon $occurredAt): string
    {
        $period = $occurredAt->format('Y-m');

        return $this->isLocked($period) ? Carbon::now()->format('Y-m') : $period;
    }

    /**
     * @throws ValidationException
     */
    public function lock(string $period, User $actor, ?string $note = null): AccountingPeriod
    {
        if ($period >= Carbon::now()->format('Y-m')) {
            throw ValidationException::withMessages(['period' => ['Only a finished month can be closed.']]);
        }

        $this->locked = [];

        return AccountingPeriod::query()->updateOrCreate(
            ['period' => $period],
            ['status' => 'locked', 'locked_by' => $actor->id, 'locked_at' => Carbon::now(), 'note' => $note],
        );
    }
}
