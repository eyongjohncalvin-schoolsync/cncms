<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopesRouteBindingToBranch;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer registered by a field agent offline
 * (docs/plans/installation-and-promotions.md, phase 3, D10) — written only
 * by App\Services\FieldInstallationService.
 *
 * `proposal` is what the agent chose on the phone (offer, fee, amount paid,
 * free/paid months per service, connection date, method); the money itself
 * is recorded at sync as real payments with their field receipts
 * (payments.field_installation_id). The customer waits as "pending
 * activation" (customers.activation_status) until the office activates —
 * possibly correcting zone, free months and connection date (`corrections`)
 * — or rejects it with a reason (`decision_note`, the refund bon).
 *
 * @property array<string, mixed> $proposal
 * @property ?array<int, array<string, mixed>> $possible_duplicates
 * @property ?array<string, mixed> $corrections
 * @property string $status
 */
#[Fillable([
    'local_uuid', 'customer_id', 'customer_charge_id', 'submitted_by', 'recorded_by_device', 'collected_at',
    'proposal', 'possible_duplicates', 'status', 'decided_by', 'decided_at', 'decision_note', 'corrections', 'refund_voucher_id',
])]
#[RouteKey('uuid')]
class FieldInstallation extends Model
{
    use Auditable, BelongsToBranch, HasUuid, ScopesRouteBindingToBranch;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ACTIVATED = 'activated';

    public const string STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'proposal' => 'array',
            'possible_duplicates' => 'array',
            'corrections' => 'array',
            'collected_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(CustomerCharge::class, 'customer_charge_id');
    }

    /** The agent who registered the customer (central users). */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function refundVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'refund_voucher_id');
    }

    /**
     * The money taken with the registration (installation, months paid upfront).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Its branch comes from its customer (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'customer';
    }
}
