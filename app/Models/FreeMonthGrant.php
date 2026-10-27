<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BumpsCacheVersion;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopesRouteBindingToBranch;
use App\Models\Scopes\BranchScope;
use App\Support\CacheVersion;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Free months for one service of one customer
 * (docs/plans/installation-and-promotions.md, phase 2) — given with an
 * installation offer, as compensation for poor service (D12), or carried in
 * from paper at go-live (opening balances). One row per (customer, service)
 * per request; the rows of one request share `group_uuid` and are approved
 * or rejected together. `service_id` null = every service the customer holds.
 *
 * Free months run BY CALENDAR (D5) from `start_period` for `months` months —
 * they do not pause while the customer is disconnected — so the billing
 * engine reads them statelessly (App\Support\Billing\FreeMonthPlan); nothing
 * is counted down on the manuscript. They are zero-value: never income,
 * arrears, credit, deferred revenue or refundable, and unaffected by a rate
 * change.
 *
 * Lifecycle (App\Services\FreeMonthGrantService is the only writer):
 *  - installation offer, standard months → awaiting_installation_payment,
 *    then approved (with a start_period) the month after the installation
 *    charge is fully paid or waived (D3, D4);
 *  - anything beyond the offer, and every compensation grant →
 *    pending_approval → approved | rejected (maker ≠ checker, D8);
 *  - cancelled — revoked (installation refunded), with any not-yet-used
 *    months forfeited.
 *
 * @property int $months
 * @property ?string $start_period
 */
#[Fillable([
    'group_uuid', 'customer_id', 'service_id', 'customer_charge_id', 'installation_offer_id', 'arrears_adjustment_id',
    'reason', 'note', 'months', 'standard_months', 'status', 'start_period',
    'requested_by', 'decided_by', 'decided_at', 'rejection_reason', 'cancelled_at', 'cancel_reason',
    'compensation_batch_id',
])]
#[RouteKey('uuid')]
class FreeMonthGrant extends Model
{
    use Auditable, BelongsToBranch, BumpsCacheVersion, HasUuid, ScopesRouteBindingToBranch;

    /** @return list<string> */
    public function cacheVersions(): array
    {
        return [CacheVersion::CUSTOMERS];
    }

    public const string REASON_INSTALLATION_OFFER = 'installation_offer';

    public const string REASON_COMPENSATION = 'compensation';

    public const string REASON_OPENING_BALANCE = 'opening_balance';

    public const string STATUS_AWAITING_INSTALLATION_PAYMENT = 'awaiting_installation_payment';

    public const string STATUS_PENDING_APPROVAL = 'pending_approval';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const array REASON_LABELS = [
        self::REASON_INSTALLATION_OFFER => 'Installation offer',
        self::REASON_COMPENSATION => 'Compensation',
        self::REASON_OPENING_BALANCE => 'Carried in at go-live',
    ];

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'standard_months' => 'integer',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScope(BranchScope::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(CustomerCharge::class, 'customer_charge_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(InstallationOffer::class, 'installation_offer_id');
    }

    public function arrearsAdjustment(): BelongsTo
    {
        return $this->belongsTo(ArrearsAdjustment::class);
    }

    /** A zone- or branch-wide compensation this request is part of. */
    public function compensationBatch(): BelongsTo
    {
        return $this->belongsTo(CompensationBatch::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Grants that are running or will run: approved with a start month.
     *
     * @param  Builder<FreeMonthGrant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_APPROVED)->whereNotNull('start_period');
    }

    /** The last free month (`Y-m`), or null before the grant has started. */
    public function endPeriod(): ?string
    {
        if ($this->start_period === null) {
            return null;
        }

        return self::addMonths($this->start_period, $this->months - 1);
    }

    public function coversPeriod(string $period): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->start_period !== null
            && $this->start_period <= $period
            && $period <= (string) $this->endPeriod();
    }

    /** `Y-m` + $months, never overflowing a short month. */
    public static function addMonths(string $period, int $months): string
    {
        // `!Y-m` pins the parse to the 1st — see the !Y-m gotcha.
        return Carbon::createFromFormat('!Y-m', $period)->addMonthsNoOverflow($months)->format('Y-m');
    }

    /** Its branch comes from its customer (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'customer';
    }

    /** Customer history: stays whole for its customer; fenced on lists and links instead (BelongsToBranch). */
    protected static function fencedByBranchScope(): bool
    {
        return false;
    }
}
