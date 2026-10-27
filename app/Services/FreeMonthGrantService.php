<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CommandRun;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\FreeMonthGrant;
use App\Models\InstallationOffer;
use App\Models\Manuscript;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Free months (docs/plans/installation-and-promotions.md, phase 2 +
 * revision D15–D20) — the only writer of App\Models\FreeMonthGrant.
 *
 *  - createInstallationGrants() — the free months chosen per service on the
 *    add-customer form: one consecutive run per service from the month the
 *    user picked (D15/D16). They apply straight away only when the
 *    installation is fully paid at registration, the months are within the
 *    company cap and within the offer picked; otherwise an admin/owner must
 *    approve them (D17, D19, D8).
 *  - requestCompensation() — free months for poor service (D12), always
 *    approved by someone else (maker ≠ checker, the owner excepted). On
 *    approval each service's months are placed after that service's
 *    existing free and paid months (D20), so prepaid value is never lost.
 *  - approveGroup() / rejectGroup() — decide a whole request at once.
 *  - forfeitForCharge() — a refunded installation's unused free months.
 *
 * Installation months may start in a past (locked) month — a late
 * registration's free months count from the real connection (owner,
 * 2026-10-26); they run by calendar, so a closed month is simply used.
 * Compensation and carried-in months never start in the past. Every change
 * recomputes the customer's manuscripts from the grant's start month (rows
 * already generated for that month or later, never a locked one), so no
 * month is double-given or skipped.
 */
class FreeMonthGrantService
{
    /**
     * While deferRecalculation() runs: customer id => [customer, earliest
     * month to recompute from].
     *
     * @var array<int, array{customer: Customer, from: string}>|null
     */
    private ?array $deferred = null;

    public function __construct(
        private readonly CustomerManuscriptRecalculationService $recalculation,
        private readonly ManuscriptRunLockService $lock,
    ) {}

    /**
     * Runs $callback, collecting the manuscript recalculations it asks for
     * and running each customer's once at the end — the add-customer save
     * records a charge, grants and payments in one go and must recompute
     * the generated months once, after all of them.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function deferRecalculation(callable $callback): mixed
    {
        if ($this->deferred !== null) {
            return $callback();
        }

        $this->deferred = [];

        try {
            $result = $callback();
            $pending = $this->deferred;
        } finally {
            $this->deferred = null;
        }

        foreach ($pending as $entry) {
            $this->recalculateFrom($entry['customer'], $entry['from']);
        }

        return $result;
    }

    /** The company's cap on free months per grant (D8/D16, default 3). */
    public static function maxMonths(): int
    {
        return max(1, (int) (Company::cached()?->max_free_months ?? 3));
    }

    /**
     * The free months chosen on the add-customer form, one row per service,
     * as one request (D15).
     *
     * @param  array<int, array{from: string, months: int}>  $chosen  service id => first free month + how many
     * @param  bool  $needsApproval  decided by the caller (installation not fully paid, beyond the cap or the offer)
     * @return Collection<int, FreeMonthGrant>
     */
    public function createInstallationGrants(Customer $customer, ?CustomerCharge $charge, ?InstallationOffer $offer, array $chosen, bool $needsApproval, User $actor, ?string $note = null): Collection
    {
        $chosen = array_filter($chosen, fn (array $row): bool => $row['months'] > 0);

        if ($chosen === []) {
            return new Collection;
        }

        $this->assertServicesHeld($customer, array_keys($chosen));

        $standard = $offer?->monthsByService() ?? [];
        $groupUuid = (string) Str::uuid();
        $grants = new Collection;

        foreach ($chosen as $serviceId => $row) {
            $grants->push(FreeMonthGrant::query()->create([
                'group_uuid' => $groupUuid,
                'customer_id' => $customer->id,
                'service_id' => $serviceId,
                'customer_charge_id' => $charge?->id,
                'installation_offer_id' => $offer?->id,
                'reason' => FreeMonthGrant::REASON_INSTALLATION_OFFER,
                'note' => $note,
                'months' => $row['months'],
                'standard_months' => $offer !== null ? ($standard[$serviceId] ?? 0) : null,
                'status' => $needsApproval ? FreeMonthGrant::STATUS_PENDING_APPROVAL : FreeMonthGrant::STATUS_APPROVED,
                // The month chosen on the form, kept while awaiting approval
                // so approving starts exactly there — a closed month too for
                // a late registration: free months count from the real
                // connection (owner, 2026-10-26). By calendar, so a run that
                // started in a closed month simply has fewer months left.
                'start_period' => $row['from'],
                'requested_by' => $actor->id,
                'decided_by' => $needsApproval ? null : $actor->id,
                'decided_at' => $needsApproval ? null : now(),
            ]));
        }

        if (! $needsApproval) {
            $this->recalculateFrom($customer, (string) $grants->min('start_period'));
            $customer->touch();
        }

        return $grants;
    }

    /**
     * Free months for poor service (D12) — every service or the ones named,
     * one row per service so each lands on its own timeline (D20).
     *
     * Owner's rule (2026-10-25): whoever asks may give one customer up to
     * the company's `compensation_months_without_approval` a year on their
     * own — those start straight away. Anything beyond it, and every
     * zone- or branch-wide compensation ($batchId), waits for someone
     * else's approval.
     *
     * @param  list<int>|null  $serviceIds  null = every service the customer holds
     * @return Collection<int, FreeMonthGrant>
     */
    public function requestCompensation(Customer $customer, ?array $serviceIds, int $months, string $note, User $actor, ?int $arrearsAdjustmentId = null, ?int $batchId = null): Collection
    {
        $this->assertWithinCap([$months]);

        $serviceIds = $serviceIds === null || $serviceIds === []
            ? $this->heldServiceIds($customer)
            : array_values(array_unique(array_map('intval', $serviceIds)));

        $this->assertServicesHeld($customer, $serviceIds);

        $groupUuid = (string) Str::uuid();

        $grants = collect($serviceIds)->map(fn (int $serviceId): FreeMonthGrant => FreeMonthGrant::query()->create([
            'group_uuid' => $groupUuid,
            'customer_id' => $customer->id,
            'service_id' => $serviceId,
            'arrears_adjustment_id' => $arrearsAdjustmentId,
            'compensation_batch_id' => $batchId,
            'reason' => FreeMonthGrant::REASON_COMPENSATION,
            'note' => $note,
            'months' => $months,
            'status' => FreeMonthGrant::STATUS_PENDING_APPROVAL,
            'requested_by' => $actor->id,
        ]))->values();

        if ($batchId === null && $months <= self::monthsWithoutApproval() - $this->monthsGivenWithoutApproval($customer)) {
            return $this->approveGroup($groupUuid, $actor);
        }

        return $grants;
    }

    /**
     * What the "Give free months" dialog needs — on the customer page and
     * the payment page alike.
     *
     * @return array{can_request: bool, max_months: int, months_without_approval_left: int, bill: string, services: list<array{uuid: string, name: string}>}
     */
    public function formOptions(Customer $customer, User $viewer): array
    {
        $customer->loadMissing('subscriptions.service');

        return [
            'can_request' => $viewer->can('create', FreeMonthGrant::class) && ! $customer->trashed(),
            'max_months' => self::maxMonths(),
            // Months the asker may still give this customer this year on their own (the rest wait for approval).
            'months_without_approval_left' => max(0, self::monthsWithoutApproval() - $this->monthsGivenWithoutApproval($customer)),
            // For "% off the monthly bill" → write-off amount.
            'bill' => (string) $customer->bill,
            'services' => $customer->subscriptions
                ->filter(fn ($row): bool => $row->service_variant_id === null)
                ->map(fn ($row): array => ['uuid' => (string) $row->service?->uuid, 'name' => (string) $row->service?->name])
                ->values()
                ->all(),
        ];
    }

    /** Free months one customer may be given a year without approval (0 = always ask). */
    public static function monthsWithoutApproval(): int
    {
        return max(0, (int) (Company::cached()?->compensation_months_without_approval ?? 1));
    }

    /**
     * Compensation months this customer was given this year without anyone
     * else approving (asked and decided by the same person, outside a batch)
     * — one count per request, whatever the number of services in it.
     */
    public function monthsGivenWithoutApproval(Customer $customer): int
    {
        return (int) FreeMonthGrant::query()
            ->where('customer_id', $customer->id)
            ->where('reason', FreeMonthGrant::REASON_COMPENSATION)
            ->where('status', FreeMonthGrant::STATUS_APPROVED)
            ->whereNull('compensation_batch_id')
            ->whereColumn('decided_by', 'requested_by')
            ->where('decided_at', '>=', now()->startOfYear())
            ->groupBy('group_uuid')
            ->selectRaw('max(months) as months')
            ->get()
            ->sum('months');
    }

    /**
     * Free months a customer still had on paper at go-live (opening
     * balances) — already decided by whoever approved the batch, so they
     * start straight away at the go-live month (or the current month if
     * that has passed).
     */
    public function carryInAtGoLive(Customer $customer, int $months, string $goLivePeriod, ?User $actor): FreeMonthGrant
    {
        return FreeMonthGrant::query()->create([
            'group_uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'service_id' => null,
            'reason' => FreeMonthGrant::REASON_OPENING_BALANCE,
            'note' => 'Free months remaining at go-live',
            'months' => min($months, 36),
            'status' => FreeMonthGrant::STATUS_APPROVED,
            'start_period' => max($goLivePeriod, $this->lock->currentPeriod()),
            'requested_by' => $actor?->id,
            'decided_by' => $actor?->id,
            'decided_at' => now(),
        ]);
    }

    /**
     * Approves a whole request. Installation months start where they were
     * chosen (never in a past month); compensation months go after each
     * service's existing free and paid months (D20).
     */
    public function approveGroup(string $groupUuid, User $actor): Collection
    {
        return DB::transaction(function () use ($groupUuid, $actor): Collection {
            $grants = $this->lockedPendingGroup($groupUuid);
            $customer = $grants->first()->customer;
            $current = $this->lock->currentPeriod();

            foreach ($grants->sortBy('id') as $grant) {
                // Installation months keep the month chosen — approving late
                // must not move them forward and give the used ones back.
                $start = $grant->reason === FreeMonthGrant::REASON_COMPENSATION
                    ? $this->compensationStart($customer, $grant->service_id !== null ? (int) $grant->service_id : null)
                    : (string) ($grant->start_period ?? $current);

                $grant->update([
                    'status' => FreeMonthGrant::STATUS_APPROVED,
                    'start_period' => $start,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ]);
            }

            $this->recalculateFrom($customer, (string) $grants->map->fresh()->min('start_period'));
            $customer->touch();
            $this->forgetCustomerCache($customer);

            return $grants->map->fresh();
        });
    }

    public function rejectGroup(string $groupUuid, User $actor, ?string $reason): Collection
    {
        return DB::transaction(function () use ($groupUuid, $actor, $reason): Collection {
            $grants = $this->lockedPendingGroup($groupUuid);

            $grants->each->update([
                'status' => FreeMonthGrant::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->forgetCustomerCache($grants->first()->customer);

            return $grants->map->fresh();
        });
    }

    /**
     * D20: the first month the service would be billed after its existing
     * free and paid months (never before next month) — compensation goes
     * there, so no paid month is ever spent on a free one.
     */
    public function compensationStart(Customer $customer, ?int $serviceId): string
    {
        $nextMonth = FreeMonthGrant::addMonths($this->lock->currentPeriod(), 1);
        $timelines = app(CustomerCoverageService::class)->summarize($customer)['services'];
        $start = $nextMonth;

        foreach ($timelines as $timeline) {
            if ($serviceId !== null && $timeline['service_id'] !== $serviceId) {
                continue;
            }

            $firstBilled = $timeline['first_billed'];

            if ($firstBilled !== null && $firstBilled > $start) {
                $start = $firstBilled;
            }
        }

        // Never overlap free months the service already has.
        $existing = FreeMonthGrant::query()->active()->where('customer_id', $customer->id)->orderBy('start_period')->get();

        do {
            $moved = false;

            foreach ($existing as $grant) {
                $sameService = $serviceId === null || $grant->service_id === null || (int) $grant->service_id === $serviceId;

                if ($sameService && $grant->start_period <= $start && $start <= $grant->endPeriod()) {
                    $start = FreeMonthGrant::addMonths((string) $grant->endPeriod(), 1);
                    $moved = true;
                }
            }
        } while ($moved);

        return $start;
    }

    /**
     * A refunded installation forfeits the free months it came with (D9):
     * nothing from next month on. Months already given stay given.
     */
    public function forfeitForCharge(CustomerCharge $charge, string $reason): void
    {
        $forfeitFrom = FreeMonthGrant::addMonths($this->lock->currentPeriod(), 1);
        $recalculate = false;

        $grants = FreeMonthGrant::query()
            ->where('customer_charge_id', $charge->id)
            ->whereIn('status', [FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT, FreeMonthGrant::STATUS_PENDING_APPROVAL, FreeMonthGrant::STATUS_APPROVED])
            ->get();

        foreach ($grants as $grant) {
            $approved = $grant->status === FreeMonthGrant::STATUS_APPROVED;
            $used = $approved && $grant->start_period !== null ? max(0, self::monthsBetween($grant->start_period, $forfeitFrom)) : 0;

            if ($approved && $used >= $grant->months) {
                continue;
            }

            $recalculate = $recalculate || $approved;

            $grant->update($used > 0
                ? ['months' => $used, 'cancelled_at' => now(), 'cancel_reason' => $reason]
                : ['status' => FreeMonthGrant::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
        }

        if ($recalculate) {
            $this->recalculateFrom($charge->customer, $forfeitFrom);
        }

        $charge->customer->touch();
    }

    /**
     * Writes this customer's manuscript for every month from $from on that
     * has already been generated (or is being generated) — so a new
     * customer created after a month's run still gets that month's row
     * (nothing is skipped) and a grant that starts in an already generated
     * month takes effect there (nothing is double-given). Never touches a
     * locked (past) month.
     */
    public function recalculateFrom(Customer $customer, string $from): void
    {
        // A field registration awaiting activation has no rows to write:
        // activation recalculates it (installation phase 3).
        if ($customer->isPendingActivation()) {
            return;
        }

        $from = max($from, $this->lock->currentPeriod());

        if ($this->deferred !== null) {
            $earlier = $this->deferred[$customer->id]['from'] ?? null;
            $this->deferred[$customer->id] = ['customer' => $customer, 'from' => $earlier === null ? $from : min($earlier, $from)];

            return;
        }

        $periods = Manuscript::query()->where('period', '>=', $from)->distinct()->pluck('period')
            ->merge(CommandRun::query()
                ->where('command', 'manuscript:calculate')
                ->whereIn('status', ['queued', 'pending_review', 'published'])
                ->where('period', '>=', $from)
                ->pluck('period'))
            ->unique()
            ->sort()
            ->values();

        foreach ($periods as $period) {
            $this->recalculation->recalculateOne($customer, (string) $period, trigger: 'free_months', auditContext: [
                'triggered_by_user_id' => auth()->id(),
            ]);
        }
    }

    /** Whole months from $from to $to (`Y-m`), negative when $to is earlier. */
    public static function monthsBetween(string $from, string $to): int
    {
        [$fromYear, $fromMonth] = array_map('intval', explode('-', $from));
        [$toYear, $toMonth] = array_map('intval', explode('-', $to));

        return ($toYear - $fromYear) * 12 + ($toMonth - $fromMonth);
    }

    /**
     * @return Collection<int, FreeMonthGrant>
     */
    private function lockedPendingGroup(string $groupUuid): Collection
    {
        $grants = FreeMonthGrant::query()->where('group_uuid', $groupUuid)->lockForUpdate()->get();

        if ($grants->isEmpty() || $grants->contains(fn (FreeMonthGrant $grant): bool => $grant->status !== FreeMonthGrant::STATUS_PENDING_APPROVAL)) {
            throw ValidationException::withMessages(['grant' => 'These free months are not waiting for approval.']);
        }

        return $grants;
    }

    /**
     * @param  array<int|string, int>  $months
     */
    private function assertWithinCap(array $months): void
    {
        $max = self::maxMonths();

        foreach ($months as $value) {
            if ($value < 1 || $value > $max) {
                throw ValidationException::withMessages(['months' => "Free months must be between 1 and {$max} per grant."]);
            }
        }
    }

    /**
     * @param  list<int>  $serviceIds
     */
    private function assertServicesHeld(Customer $customer, array $serviceIds): void
    {
        if (array_diff($serviceIds, $this->heldServiceIds($customer)) !== []) {
            throw ValidationException::withMessages(['services' => 'Free months can only be given on services the customer holds.']);
        }
    }

    /**
     * @return list<int>
     */
    private function heldServiceIds(Customer $customer): array
    {
        return DB::table('customer_service')->where('customer_id', $customer->id)->distinct()->pluck('service_id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    private function forgetCustomerCache(Customer $customer): void
    {
        CustomerService::forgetShowCache($customer->uuid, $customer->branch_id);
    }
}
