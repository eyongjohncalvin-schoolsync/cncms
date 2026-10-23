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
use App\Models\Payment;
use App\Models\User;
use App\Support\BusinessTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Free months (docs/plans/installation-and-promotions.md, phase 2) — the
 * only writer of App\Models\FreeMonthGrant.
 *
 *  - createInstallationGrants() — the free months picked in the add-customer
 *    flow. Up to the offer's months per service they need no approval and
 *    wait for the installation to be fully paid or waived (D3); anything
 *    beyond the offer needs admin/owner approval (D8).
 *  - installationSettled() / installationReopened() — called from
 *    CustomerCharge::refreshStatus() and the waiver approval: the grants
 *    start the month AFTER the installation is settled, even if that
 *    month's bills were already generated (D4), and go back on hold if the
 *    settling payment is later rejected.
 *  - requestCompensation() — free months for poor service (D12), always
 *    approved by someone else (maker ≠ checker, the owner excepted).
 *  - approveGroup() / rejectGroup() — decide a whole request at once.
 *  - forfeitForCharge() — a refunded installation's unused free months.
 *
 * Start month rules: never before the current month (a locked period is
 * never rewritten), never before the customer's first billed month, never
 * overlapping free months the same service already has (they stack), and —
 * for a request that frees only some of the customer's services — not
 * while the customer still has paid months, so a paid month is never spent
 * on a half-free month (D6: the paid months are kept whole).
 *
 * Every change recomputes the customer's manuscripts from the grant's
 * start month (rows already generated for that month or later), so no month
 * is double-given or skipped.
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

    /** The company's cap on free months per grant (D8, default 3). */
    public static function maxMonths(): int
    {
        return max(1, (int) (Company::cached()?->max_free_months ?? 3));
    }

    /**
     * The free months that come with a new installation, one row per
     * service, as one request.
     *
     * @param  array<int, int>  $monthsByService  service id => free months (0 = none)
     * @return Collection<int, FreeMonthGrant>
     */
    public function createInstallationGrants(Customer $customer, ?CustomerCharge $charge, ?InstallationOffer $offer, array $monthsByService, User $actor, ?string $note = null): Collection
    {
        $monthsByService = array_filter($monthsByService, fn (int $months): bool => $months > 0);

        if ($monthsByService === []) {
            return new Collection;
        }

        $this->assertWithinCap($monthsByService);
        $this->assertServicesHeld($customer, array_keys($monthsByService));

        $standard = $offer?->monthsByService() ?? [];
        $beyondOffer = collect($monthsByService)->contains(fn (int $months, int $serviceId): bool => $months > ($standard[$serviceId] ?? 0));

        $status = match (true) {
            $beyondOffer => FreeMonthGrant::STATUS_PENDING_APPROVAL,
            default => FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT,
        };

        $groupUuid = (string) Str::uuid();
        $grants = new Collection;

        foreach ($monthsByService as $serviceId => $months) {
            $grants->push(FreeMonthGrant::query()->create([
                'group_uuid' => $groupUuid,
                'customer_id' => $customer->id,
                'service_id' => $serviceId,
                'customer_charge_id' => $charge?->id,
                'installation_offer_id' => $offer?->id,
                'reason' => FreeMonthGrant::REASON_INSTALLATION_OFFER,
                'note' => $note,
                'months' => $months,
                'standard_months' => $standard[$serviceId] ?? 0,
                'status' => $status,
                'requested_by' => $actor->id,
            ]));
        }

        // No fee at all (a free installation offer) or a fee already settled
        // at the counter: the months start now (the month after).
        if ($status === FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT && ($charge === null || $charge->fresh()->isSettled())) {
            $this->startAwaiting($customer, $grants, $this->monthAfterSettlement($charge));
        }

        return $grants->map->fresh();
    }

    /**
     * Free months for poor service (D12) — every service or the ones named.
     * Always waits for someone else's approval.
     *
     * @param  list<int>|null  $serviceIds  null = every service the customer holds
     * @return Collection<int, FreeMonthGrant>
     */
    public function requestCompensation(Customer $customer, ?array $serviceIds, int $months, string $note, User $actor, ?int $arrearsAdjustmentId = null): Collection
    {
        $this->assertWithinCap([$months]);

        if ($serviceIds !== null) {
            $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));
            $this->assertServicesHeld($customer, $serviceIds);

            // Naming every service is the same as "all services".
            $held = $this->heldServiceIds($customer);
            sort($held);
            $sorted = $serviceIds;
            sort($sorted);

            if ($sorted === $held) {
                $serviceIds = null;
            }
        }

        $groupUuid = (string) Str::uuid();

        return collect($serviceIds ?? [null])->map(fn (?int $serviceId): FreeMonthGrant => FreeMonthGrant::query()->create([
            'group_uuid' => $groupUuid,
            'customer_id' => $customer->id,
            'service_id' => $serviceId,
            'arrears_adjustment_id' => $arrearsAdjustmentId,
            'reason' => FreeMonthGrant::REASON_COMPENSATION,
            'note' => $note,
            'months' => $months,
            'status' => FreeMonthGrant::STATUS_PENDING_APPROVAL,
            'requested_by' => $actor->id,
        ]))->values();
    }

    /**
     * Free months a customer still had on paper at go-live (opening
     * balances) — already decided by whoever approved the batch, so they
     * start straight away at the go-live month (or the current month if
     * that has passed).
     */
    public function carryInAtGoLive(Customer $customer, int $months, string $goLivePeriod, ?User $actor): FreeMonthGrant
    {
        $grant = FreeMonthGrant::query()->create([
            'group_uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'service_id' => null,
            'reason' => FreeMonthGrant::REASON_OPENING_BALANCE,
            'note' => 'Free months remaining at go-live',
            'months' => min($months, 36),
            'status' => FreeMonthGrant::STATUS_APPROVED,
            'start_period' => $this->startFor($customer, $goLivePeriod, null, true),
            'requested_by' => $actor?->id,
            'decided_by' => $actor?->id,
            'decided_at' => now(),
        ]);

        return $grant;
    }

    /**
     * Approves a whole request. Installation months still wait for the fee
     * to be settled; everything else starts at its start month.
     */
    public function approveGroup(string $groupUuid, User $actor): Collection
    {
        return DB::transaction(function () use ($groupUuid, $actor): Collection {
            $grants = $this->lockedPendingGroup($groupUuid);
            $customer = $grants->first()->customer;

            foreach ($grants as $grant) {
                $grant->update(['decided_by' => $actor->id, 'decided_at' => now()]);
            }

            $first = $grants->first();

            if ($first->reason === FreeMonthGrant::REASON_INSTALLATION_OFFER) {
                $grants->each->update(['status' => FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT]);
                $charge = $first->charge;

                if ($charge === null || $charge->isSettled()) {
                    $this->startAwaiting($customer, $grants, $this->monthAfterSettlement($charge));
                }
            } else {
                $this->start($customer, $grants, $this->nextMonth());
            }

            $this->forgetCustomerCache($customer);

            return $grants->map->fresh();
        });
    }

    /**
     * Rejects a request. For installation months asked beyond the offer,
     * the offer's own months still stand (they never needed approval) — the
     * rejection only takes away the extra.
     */
    public function rejectGroup(string $groupUuid, User $actor, ?string $reason): Collection
    {
        return DB::transaction(function () use ($groupUuid, $actor, $reason): Collection {
            $grants = $this->lockedPendingGroup($groupUuid);
            $customer = $grants->first()->customer;
            $keep = new Collection;

            foreach ($grants as $grant) {
                $standard = (int) ($grant->standard_months ?? 0);

                if ($grant->reason === FreeMonthGrant::REASON_INSTALLATION_OFFER && $standard > 0) {
                    $grant->update([
                        'months' => min($grant->months, $standard),
                        'status' => FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT,
                        'decided_by' => $actor->id,
                        'decided_at' => now(),
                        'rejection_reason' => $reason,
                    ]);
                    $keep->push($grant);

                    continue;
                }

                $grant->update([
                    'status' => FreeMonthGrant::STATUS_REJECTED,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                    'rejection_reason' => $reason,
                ]);
            }

            $charge = $grants->first()->charge;

            if ($keep->isNotEmpty() && ($charge === null || $charge->isSettled())) {
                $this->startAwaiting($customer, $keep, $this->monthAfterSettlement($charge));
            }

            $this->forgetCustomerCache($customer);

            return $grants->map->fresh();
        });
    }

    /**
     * The installation charge became paid or waived: its waiting free months
     * start the month after (D3, D4).
     */
    public function installationSettled(CustomerCharge $charge): void
    {
        $grants = FreeMonthGrant::query()
            ->where('customer_charge_id', $charge->id)
            ->where('status', FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT)
            ->get();

        if ($grants->isEmpty()) {
            return;
        }

        $this->startAwaiting($charge->customer, $grants, $this->monthAfterSettlement($charge));
    }

    /**
     * A payment that had settled the installation was rejected or removed:
     * months that have not started yet go back on hold (D3). Months already
     * running are left alone — they were given.
     */
    public function installationReopened(CustomerCharge $charge): void
    {
        $current = $this->lock->currentPeriod();

        $grants = FreeMonthGrant::query()
            ->where('customer_charge_id', $charge->id)
            ->where('status', FreeMonthGrant::STATUS_APPROVED)
            ->where('start_period', '>', $current)
            ->get();

        if ($grants->isEmpty()) {
            return;
        }

        $from = $grants->min('start_period');

        $grants->each->update(['status' => FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT, 'start_period' => null]);

        $this->recalculateFrom($charge->customer, $from);
    }

    /**
     * A refunded installation forfeits the free months it came with (D9):
     * nothing from next month on. Months already given stay given.
     */
    public function forfeitForCharge(CustomerCharge $charge, string $reason): void
    {
        $forfeitFrom = $this->nextMonth();
        $recalculateFrom = null;

        $grants = FreeMonthGrant::query()
            ->where('customer_charge_id', $charge->id)
            ->whereIn('status', [FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT, FreeMonthGrant::STATUS_PENDING_APPROVAL, FreeMonthGrant::STATUS_APPROVED])
            ->get();

        foreach ($grants as $grant) {
            $used = $grant->start_period === null ? 0 : max(0, self::monthsBetween($grant->start_period, $forfeitFrom));

            if ($grant->status === FreeMonthGrant::STATUS_APPROVED && $used >= $grant->months) {
                continue;
            }

            if ($grant->status === FreeMonthGrant::STATUS_APPROVED) {
                $recalculateFrom = $recalculateFrom === null ? $forfeitFrom : min($recalculateFrom, $forfeitFrom);
            }

            $grant->update($used > 0
                ? ['months' => $used, 'cancelled_at' => now(), 'cancel_reason' => $reason]
                : ['status' => FreeMonthGrant::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
        }

        if ($recalculateFrom !== null) {
            $this->recalculateFrom($charge->customer, $recalculateFrom);
        }

        $charge->customer->touch();
    }

    /**
     * Writes this customer's manuscript for every month from $from on that
     * has already been generated (or is being generated) — so a new
     * customer created after next month's run still gets that month's row
     * (D4: nothing is skipped) and a grant that starts in an already
     * generated month takes effect there (nothing is double-given). Never
     * touches a locked (past) month.
     */
    public function recalculateFrom(Customer $customer, string $from): void
    {
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

    /**
     * The month after the installation was settled: the last verified
     * installation payment (when it was collected), or the waiver decision
     * — on the Cameroon calendar.
     */
    public function monthAfterSettlement(?CustomerCharge $charge): string
    {
        if ($charge === null) {
            return $this->nextMonth();
        }

        $settledAt = null;

        if ($charge->status === CustomerCharge::STATUS_WAIVED && $charge->waiver_decided_at !== null) {
            $settledAt = $charge->waiver_decided_at;
        } else {
            $lastPayment = $charge->payments()
                ->where('purpose', Payment::PURPOSE_INSTALLATION)
                ->where('verification_status', 'verified')
                ->get(['collected_at', 'created_at'])
                ->map(fn (Payment $payment) => $payment->collected_at ?? $payment->created_at)
                ->filter()
                ->max();

            $settledAt = $lastPayment ?? $charge->paid_at;
        }

        $settledPeriod = $settledAt !== null ? BusinessTimezone::periodOf(Carbon::parse($settledAt)) : BusinessTimezone::currentPeriod();

        return FreeMonthGrant::addMonths($settledPeriod, 1);
    }

    /**
     * The month a grant for $serviceId (null = all services) can start, at
     * the earliest $desired.
     */
    public function startFor(Customer $customer, string $desired, ?int $serviceId, bool $freesWholeBill): string
    {
        $start = max($desired, $this->lock->currentPeriod());

        if ($customer->billing_starts_period !== null && $start < $customer->billing_starts_period) {
            $start = $customer->billing_starts_period;
        }

        // Only some services free while paid months remain would spend a
        // whole paid month on a half-free one — start once they run out.
        if (! $freesWholeBill) {
            $latest = Manuscript::query()->where('customer_id', $customer->id)->orderByDesc('period')->first();

            if ($latest !== null && (int) $latest->prepaid_months_remaining > 0) {
                $start = max($start, FreeMonthGrant::addMonths($latest->period, (int) $latest->prepaid_months_remaining + 1));
            }
        }

        // Stack after free months the same service already has.
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

    /** Whole months from $from to $to (`Y-m`), negative when $to is earlier. */
    public static function monthsBetween(string $from, string $to): int
    {
        [$fromYear, $fromMonth] = array_map('intval', explode('-', $from));
        [$toYear, $toMonth] = array_map('intval', explode('-', $to));

        return ($toYear - $fromYear) * 12 + ($toMonth - $fromMonth);
    }

    /**
     * @param  Collection<int, FreeMonthGrant>  $grants
     */
    private function startAwaiting(Customer $customer, Collection $grants, string $desired): void
    {
        $this->start($customer, $grants, $desired);
    }

    /**
     * Approves and starts every grant of one request at its own start month
     * (stacked per service), then recomputes the generated months.
     *
     * @param  Collection<int, FreeMonthGrant>  $grants
     */
    private function start(Customer $customer, Collection $grants, string $desired): void
    {
        $freesWholeBill = $this->freesWholeBill($customer, $grants);
        $earliest = null;

        foreach ($grants as $grant) {
            $start = $this->startFor($customer, $desired, $grant->service_id !== null ? (int) $grant->service_id : null, $freesWholeBill);
            $grant->update(['status' => FreeMonthGrant::STATUS_APPROVED, 'start_period' => $start]);
            $earliest = $earliest === null ? $start : min($earliest, $start);
        }

        if ($earliest !== null) {
            $this->recalculateFrom($customer, $earliest);
        }

        // The mobile pull only re-sends customers whose row changed.
        $customer->touch();
        $this->forgetCustomerCache($customer);
    }

    /**
     * A request frees the whole bill when it covers every service the
     * customer holds for the same number of months.
     *
     * @param  Collection<int, FreeMonthGrant>  $grants
     */
    private function freesWholeBill(Customer $customer, Collection $grants): bool
    {
        if ($grants->contains(fn (FreeMonthGrant $grant): bool => $grant->service_id === null)) {
            return true;
        }

        $held = $this->heldServiceIds($customer);
        $granted = $grants->pluck('service_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        sort($held);

        return $granted === $held && $grants->pluck('months')->unique()->count() === 1;
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

    private function nextMonth(): string
    {
        return FreeMonthGrant::addMonths($this->lock->currentPeriod(), 1);
    }

    private function forgetCustomerCache(Customer $customer): void
    {
        Cache::forget("customers:show:{$customer->uuid}:all");
    }
}
