<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Budget;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The rules that decide whether an expense is approved automatically or
 * waits for people, and the red flags it carries. Every rule is about one
 * fraud pattern:
 *
 * - amount tiers: automatic below a limit, a manager up to a second limit,
 *   then manager + owner (`large`);
 * - split purchases: the same person's spending on the same category or
 *   supplier over the last 72 hours is added up before the tier is chosen;
 * - receipts: required from a limit; the expense waits (`pending_evidence`)
 *   until the photo arrives, and a photo already used elsewhere is flagged;
 * - backdating, duplicate entries, unapproved or missing suppliers and
 *   budget overruns always need a person;
 * - round amounts, amounts just under a limit and late-night entries are
 *   flagged for the owner's red-flag list without blocking anything.
 *
 * The requester can never approve their own expense, and the two approvals
 * on a large one must come from two different people.
 */
class ExpenseControlService
{
    public const string FLAG_BACKDATED = 'backdated';

    public const string FLAG_ROUND_AMOUNT = 'round_amount';

    public const string FLAG_NEAR_LIMIT = 'near_limit';

    public const string FLAG_LATE_NIGHT = 'late_night';

    public const string FLAG_SPLIT = 'split';

    public const string FLAG_NO_RECEIPT = 'no_receipt';

    public const string FLAG_DUPLICATE_RECEIPT = 'duplicate_receipt';

    public const string FLAG_DUPLICATE_ENTRY = 'duplicate_entry';

    public const string FLAG_NO_SUPPLIER = 'no_supplier';

    public const string FLAG_NEW_SUPPLIER = 'new_supplier';

    public const string FLAG_OVER_BUDGET = 'over_budget';

    public const string FLAG_BUDGET_WARNING = 'budget_warning';

    public const string FLAG_LARGE = 'large';

    /** Plain-language label for every flag, for the web and the app. */
    public const array FLAG_LABELS = [
        self::FLAG_BACKDATED => 'Dated in the past',
        self::FLAG_ROUND_AMOUNT => 'Round amount',
        self::FLAG_NEAR_LIMIT => 'Just under an approval limit',
        self::FLAG_LATE_NIGHT => 'Recorded late at night',
        self::FLAG_SPLIT => 'Looks split to stay under a limit',
        self::FLAG_NO_RECEIPT => 'Receipt photo missing',
        self::FLAG_DUPLICATE_RECEIPT => 'Receipt photo used before',
        self::FLAG_DUPLICATE_ENTRY => 'Possible duplicate',
        self::FLAG_NO_SUPPLIER => 'No supplier named',
        self::FLAG_NEW_SUPPLIER => 'Supplier not yet approved',
        self::FLAG_OVER_BUDGET => 'Over budget',
        self::FLAG_BUDGET_WARNING => 'Budget nearly used',
        self::FLAG_LARGE => 'Large amount',
    ];

    /** Flags that always send an expense to a person. */
    private const array BLOCKING_FLAGS = [
        self::FLAG_BACKDATED,
        self::FLAG_SPLIT,
        self::FLAG_DUPLICATE_RECEIPT,
        self::FLAG_DUPLICATE_ENTRY,
        self::FLAG_NO_SUPPLIER,
        self::FLAG_NEW_SUPPLIER,
        self::FLAG_LARGE,
    ];

    private const int SPLIT_WINDOW_HOURS = 72;

    private const string TIMEZONE = 'Africa/Douala';

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The approval and evidence limits from Settings (companies row).
     *
     * @return array{auto_approve_below: string, second_approval_above: string, receipt_required_from: string, supplier_required_from: string, backdate_days: int}
     */
    public function limits(): array
    {
        $company = Company::cached();

        return [
            'auto_approve_below' => (string) ($company?->expense_auto_approve_below ?? '5000.00'),
            'second_approval_above' => (string) ($company?->expense_second_approval_above ?? '50000.00'),
            'receipt_required_from' => (string) ($company?->expense_receipt_required_from ?? '2000.00'),
            'supplier_required_from' => (string) ($company?->expense_supplier_required_from ?? '10000.00'),
            'backdate_days' => (int) ($company?->expense_backdate_days ?? 1),
        ];
    }

    /**
     * Sets the expense's flags and the status it starts in (approved
     * automatically, waiting for a receipt, or waiting for approval). Used
     * for a new expense and whenever a still-pending one is edited; any
     * first approval is cleared because the facts may have changed.
     */
    public function evaluate(Expenditure $expenditure): void
    {
        $limits = $this->limits();
        $amount = bcadd((string) $expenditure->amount, '0', 2);
        $flags = $expenditure->hasFlag(self::FLAG_DUPLICATE_RECEIPT) ? [self::FLAG_DUPLICATE_RECEIPT] : [];

        $today = Carbon::now(self::TIMEZONE)->startOfDay();
        $spentAt = Carbon::parse($expenditure->spent_at, self::TIMEZONE)->startOfDay();

        if ($spentAt->lt($today->copy()->subDays($limits['backdate_days']))) {
            $flags[] = self::FLAG_BACKDATED;
        }

        if (bccomp($amount, '10000', 2) >= 0 && bcmod($amount, '5000', 2) === '0.00') {
            $flags[] = self::FLAG_ROUND_AMOUNT;
        }

        foreach ([$limits['auto_approve_below'], $limits['second_approval_above']] as $limit) {
            if (bccomp($amount, bcmul($limit, '0.9', 2), 2) >= 0 && bccomp($amount, $limit, 2) < 0) {
                $flags[] = self::FLAG_NEAR_LIMIT;
            }
        }

        $recordedAt = Carbon::parse($expenditure->device_recorded_at ?? Carbon::now())->setTimezone(self::TIMEZONE);

        if ($recordedAt->hour >= 22 || $recordedAt->hour < 5) {
            $flags[] = self::FLAG_LATE_NIGHT;
        }

        $effective = $amount;
        $windowTotal = bcadd($this->recentSpending($expenditure), $amount, 2);

        if ($this->tier($windowTotal, $limits) > $this->tier($amount, $limits)) {
            $flags[] = self::FLAG_SPLIT;
            $effective = $windowTotal;
        }

        if ($this->looksDuplicated($expenditure)) {
            $flags[] = self::FLAG_DUPLICATE_ENTRY;
        }

        if ($expenditure->supplier_id === null && bccomp($amount, $limits['supplier_required_from'], 2) >= 0) {
            $flags[] = self::FLAG_NO_SUPPLIER;
        }

        if ($expenditure->supplier_id !== null && $expenditure->supplier?->status !== 'active') {
            $flags[] = self::FLAG_NEW_SUPPLIER;
        }

        $budgetFlag = $this->budgetFlag($expenditure, $amount);

        if ($budgetFlag !== null) {
            $flags[] = $budgetFlag;
        }

        if ($this->tier($effective, $limits) === 2 || $budgetFlag === self::FLAG_OVER_BUDGET) {
            $flags[] = self::FLAG_LARGE;
        }

        $needsReceipt = $expenditure->receipt_path === null && bccomp($amount, $limits['receipt_required_from'], 2) >= 0;

        if ($needsReceipt) {
            $flags[] = self::FLAG_NO_RECEIPT;
        }

        $needsPerson = bccomp($effective, $limits['auto_approve_below'], 2) >= 0
            || array_intersect($flags, self::BLOCKING_FLAGS) !== [];

        $status = match (true) {
            $needsReceipt => Expenditure::STATUS_PENDING_EVIDENCE,
            $needsPerson => Expenditure::STATUS_PENDING_APPROVAL,
            default => Expenditure::STATUS_APPROVED,
        };

        $expenditure->forceFill([
            'flags' => array_values(array_unique($flags)),
            'status' => $status,
            'auto_approved' => $status === Expenditure::STATUS_APPROVED,
            'approved_at' => $status === Expenditure::STATUS_APPROVED ? Carbon::now() : null,
            'approved_by' => null,
            'first_approved_by' => null,
            'first_approved_at' => null,
        ]);
    }

    /**
     * After a receipt photo arrives: flags a photo already used on another
     * expense, and moves an expense that was only waiting for its receipt
     * on to approval (or straight to approved when it qualifies).
     */
    public function receiptAttached(Expenditure $expenditure): void
    {
        $usedBefore = $expenditure->receipt_sha256 !== null && Expenditure::query()
            ->where('receipt_sha256', $expenditure->receipt_sha256)
            ->whereKeyNot($expenditure->getKey())
            ->where('status', '!=', Expenditure::STATUS_VOID)
            ->exists();

        $flags = array_values(array_diff($expenditure->flags ?? [], [self::FLAG_NO_RECEIPT]));

        if ($usedBefore) {
            $flags[] = self::FLAG_DUPLICATE_RECEIPT;
        }

        $expenditure->flags = array_values(array_unique($flags));

        // A new expense, or one that was only waiting for this receipt, is
        // (re)judged with the receipt in hand.
        if (! $expenditure->exists || $expenditure->status === Expenditure::STATUS_PENDING_EVIDENCE) {
            $this->evaluate($expenditure);
        }
    }

    /**
     * Approves (or gives the first of two approvals on) a pending expense.
     *
     * @throws ValidationException
     */
    public function approve(Expenditure $expenditure, User $actor): void
    {
        $this->assertNotRequester($expenditure, $actor, 'approve');

        if ($expenditure->status === Expenditure::STATUS_PENDING_APPROVAL) {
            if ($expenditure->hasFlag(self::FLAG_LARGE)) {
                $expenditure->forceFill([
                    'status' => Expenditure::STATUS_PENDING_SECOND_APPROVAL,
                    'first_approved_by' => $actor->id,
                    'first_approved_at' => Carbon::now(),
                ])->save();

                return;
            }
        } elseif ($expenditure->status === Expenditure::STATUS_PENDING_SECOND_APPROVAL) {
            if ($expenditure->first_approved_by === $actor->id) {
                throw ValidationException::withMessages(['expenditure' => ['A different person must give the second approval.']]);
            }
        } else {
            throw ValidationException::withMessages(['expenditure' => ['This expense is not waiting for approval.']]);
        }

        $expenditure->forceFill([
            'status' => Expenditure::STATUS_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => Carbon::now(),
        ])->save();
    }

    /**
     * @throws ValidationException
     */
    public function reject(Expenditure $expenditure, User $actor, string $reason): void
    {
        if (! $expenditure->isPending()) {
            throw ValidationException::withMessages(['expenditure' => ['Only a pending expense can be rejected.']]);
        }

        $this->assertNotRequester($expenditure, $actor, 'reject');

        $expenditure->forceFill([
            'status' => Expenditure::STATUS_REJECTED,
            'rejected_by' => $actor->id,
            'rejected_at' => Carbon::now(),
            'rejection_reason' => $reason,
        ])->save();
    }

    /**
     * Voids an expense — the record stays, with who voided it and why, and
     * it stops counting. A voided expense can't come back.
     *
     * @throws ValidationException
     */
    public function void(Expenditure $expenditure, User $actor, string $reason): void
    {
        if ($expenditure->status === Expenditure::STATUS_VOID) {
            throw ValidationException::withMessages(['expenditure' => ['This expense is already void.']]);
        }

        $expenditure->forceFill([
            'status' => Expenditure::STATUS_VOID,
            'voided_by' => $actor->id,
            'voided_at' => Carbon::now(),
            'void_reason' => $reason,
        ])->save();
    }

    /**
     * Tells whoever has to act. Called after an expense is saved in a new
     * status.
     */
    public function notify(Expenditure $expenditure): void
    {
        $expenditure->loadMissing(['category', 'user']);
        $amount = number_format((float) $expenditure->amount, 0, '.', ',').' FCFA';
        $who = $expenditure->user?->name ?? 'Someone';
        $what = "{$expenditure->category?->name}: {$amount}";
        $link = '/resources/approvals';

        match ($expenditure->status) {
            Expenditure::STATUS_PENDING_APPROVAL => collect(['manager', 'admin'])->each(fn (string $role) => $this->notifications->broadcastToRole(
                $role, 'expense.pending_approval', 'info', 'Expense waiting for approval', "{$who} recorded {$what}.", $link, 'expenditure', $expenditure->uuid,
            )),
            Expenditure::STATUS_PENDING_SECOND_APPROVAL => $this->notifications->broadcastToRole(
                'super', 'expense.pending_second_approval', 'warning', 'Large expense needs your approval', "{$who} recorded {$what}; first approval given.", $link, 'expenditure', $expenditure->uuid,
            ),
            Expenditure::STATUS_APPROVED, Expenditure::STATUS_REJECTED => $expenditure->auto_approved || $expenditure->user === null ? null : $this->notifications->broadcastToUser(
                $expenditure->user,
                'expense.'.$expenditure->status,
                'info',
                $expenditure->status === Expenditure::STATUS_APPROVED ? 'Expense approved' : 'Expense rejected',
                $expenditure->status === Expenditure::STATUS_APPROVED
                    ? "Your expense {$what} was approved."
                    : "Your expense {$what} was rejected: {$expenditure->rejection_reason}",
                '/resources/expenditures',
                'expenditure',
                $expenditure->uuid,
            ),
            default => null,
        };
    }

    /**
     * 0 = automatic, 1 = one approval, 2 = two approvals.
     *
     * @param  array{auto_approve_below: string, second_approval_above: string}  $limits
     */
    private function tier(string $amount, array $limits): int
    {
        return match (true) {
            bccomp($amount, $limits['second_approval_above'], 2) > 0 => 2,
            bccomp($amount, $limits['auto_approve_below'], 2) >= 0 => 1,
            default => 0,
        };
    }

    /**
     * What the same person spent on the same category or supplier in the
     * last 72 hours (not counting rejected or voided expenses).
     */
    private function recentSpending(Expenditure $expenditure): string
    {
        $total = Expenditure::query()
            ->where('user_id', $expenditure->user_id)
            ->when($expenditure->exists, fn ($query) => $query->whereKeyNot($expenditure->getKey()))
            ->whereNotIn('status', [Expenditure::STATUS_REJECTED, Expenditure::STATUS_VOID])
            ->where('created_at', '>=', Carbon::now()->subHours(self::SPLIT_WINDOW_HOURS))
            ->where(function ($query) use ($expenditure): void {
                $query->where('category_id', $expenditure->category_id);

                if ($expenditure->supplier_id !== null) {
                    $query->orWhere('supplier_id', $expenditure->supplier_id);
                }
            })
            ->sum('amount');

        return bcadd((string) $total, '0', 2);
    }

    /**
     * Same amount, same day, same supplier (or category when no supplier)
     * as an existing live expense.
     */
    private function looksDuplicated(Expenditure $expenditure): bool
    {
        return Expenditure::query()
            ->when($expenditure->exists, fn ($query) => $query->whereKeyNot($expenditure->getKey()))
            ->whereNotIn('status', [Expenditure::STATUS_REJECTED, Expenditure::STATUS_VOID])
            ->where('amount', $expenditure->amount)
            ->whereDate('spent_at', Carbon::parse($expenditure->spent_at)->toDateString())
            ->when(
                $expenditure->supplier_id !== null,
                fn ($query) => $query->where('supplier_id', $expenditure->supplier_id),
                fn ($query) => $query->where('category_id', $expenditure->category_id),
            )
            ->exists();
    }

    /**
     * over_budget when this expense takes its category past the month's
     * budget, budget_warning from 80%; null without a budget.
     */
    private function budgetFlag(Expenditure $expenditure, string $amount): ?string
    {
        $period = Carbon::parse($expenditure->spent_at)->format('Y-m');
        $budget = Budget::query()->where('category_id', $expenditure->category_id)->where('period', $period)->value('amount');

        if ($budget === null || bccomp((string) $budget, '0', 2) <= 0) {
            return null;
        }

        $spent = Expenditure::query()
            ->where('category_id', $expenditure->category_id)
            ->when($expenditure->exists, fn ($query) => $query->whereKeyNot($expenditure->getKey()))
            ->whereNotIn('status', [Expenditure::STATUS_REJECTED, Expenditure::STATUS_VOID])
            ->whereBetween('spent_at', [
                Carbon::createFromFormat('!Y-m', $period)->startOfMonth()->toDateString(),
                Carbon::createFromFormat('!Y-m', $period)->endOfMonth()->toDateString(),
            ])
            ->sum('amount');

        $after = bcadd((string) $spent, $amount, 2);

        return match (true) {
            bccomp($after, (string) $budget, 2) > 0 => self::FLAG_OVER_BUDGET,
            bccomp($after, bcmul((string) $budget, '0.8', 2), 2) >= 0 => self::FLAG_BUDGET_WARNING,
            default => null,
        };
    }

    /**
     * @throws ValidationException
     */
    private function assertNotRequester(Expenditure $expenditure, User $actor, string $action): void
    {
        if ($expenditure->user_id === $actor->id) {
            throw ValidationException::withMessages(['expenditure' => ["You can't {$action} an expense you recorded yourself."]]);
        }
    }
}
