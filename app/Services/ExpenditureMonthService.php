<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExpenditureMonth;
use App\Models\User;
use App\Support\BusinessTimezone;
use Illuminate\Validation\ValidationException;

/**
 * Month close (E6 of docs/plans/expenditures-and-spending-controls.md).
 * On the 5th of the following month (Cameroon time) the previous month is
 * closed and its totals snapshotted; from then on its expenditures can't be
 * re-categorised or voided — corrections land in the current month,
 * pointing back at it (relates_to_period). Only the owner reopens a month,
 * with a reason; the reopen is audited and alerted.
 */
class ExpenditureMonthService
{
    /** Day of the month (Cameroon time) the previous month closes on. */
    public const int CLOSE_DAY = 5;

    public function __construct(
        private readonly SpendingControlService $spending,
        private readonly CashNotificationService $cashNotifier,
    ) {}

    public function isClosed(string $period): bool
    {
        return ExpenditureMonth::query()->where('period', $period)->whereNotNull('closed_at')->exists();
    }

    public function find(string $period): ?ExpenditureMonth
    {
        return ExpenditureMonth::query()->with(['closedBy', 'reopenedBy'])->where('period', $period)->first();
    }

    public function assertOpen(string $period, string $field = 'reason'): void
    {
        if ($this->isClosed($period)) {
            throw ValidationException::withMessages([$field => "{$this->label($period)} is closed. Post a correction in the current month instead."]);
        }
    }

    /**
     * Closes $period with a snapshot of its totals. $actor null = the
     * scheduled close. Only a month that has ended can close.
     */
    public function close(string $period, ?User $actor = null): ExpenditureMonth
    {
        if (! BusinessTimezone::isValidPeriod($period) || $period >= BusinessTimezone::currentPeriod()) {
            throw ValidationException::withMessages(['period' => 'Only a month that has ended can be closed.']);
        }

        $month = ExpenditureMonth::query()->firstOrNew(['period' => $period]);

        if ($month->isClosed()) {
            throw ValidationException::withMessages(['period' => "{$this->label($period)} is already closed."]);
        }

        $month->fill([
            'closed_at' => now(),
            'closed_by' => $actor?->id,
            'snapshot' => $this->spending->summary($period),
        ])->save();

        return $month;
    }

    public function reopen(string $period, User $owner, string $reason): ExpenditureMonth
    {
        $month = BusinessTimezone::isValidPeriod($period) ? ExpenditureMonth::query()->where('period', $period)->first() : null;

        if ($month === null || ! $month->isClosed()) {
            throw ValidationException::withMessages(['reason' => "{$period} is not a closed month."]);
        }

        $month->update([
            'closed_at' => null,
            'reopened_by' => $owner->id,
            'reopened_at' => now(),
            'reopen_reason' => $reason,
        ]);

        $this->cashNotifier->toHoldersOf(
            'expenditures.dashboard',
            'expenditures.month_reopened',
            'warning',
            "{$this->label($period)} reopened",
            "{$owner->name} reopened {$this->label($period)}'s expenditures: {$reason}",
            "/resources/expenditures?month={$period}",
        );

        return $month;
    }

    /**
     * The scheduled close: from the 5th (Cameroon time), the previous month
     * closes if it never closed before. A month the owner reopened stays
     * open until the owner closes it again.
     *
     * @return list<string> periods closed
     */
    public function closeDue(): array
    {
        if ((int) BusinessTimezone::now()->format('j') < self::CLOSE_DAY) {
            return [];
        }

        $previous = BusinessTimezone::previousPeriod();

        if (ExpenditureMonth::query()->where('period', $previous)->exists()) {
            return [];
        }

        $this->close($previous);

        return [$previous];
    }

    public function label(string $period): string
    {
        return BusinessTimezone::periodStart($period)->format('F Y');
    }
}
