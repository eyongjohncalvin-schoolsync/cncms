<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maker-checker ledger correction against one customer's arrears balance
 * for one billing period — see this feature's design doc (synthesized from
 * ledger/approval-workflow/edge-case/UX-audit deliberation) for the full
 * rationale. Structurally mirrors App\Models\Complaint: `requested_by` is
 * deliberately absent from #[Fillable] (always set explicitly by
 * App\Repositories\Eloquent\ArrearsAdjustmentRepository::create() from
 * auth()->id(), never via mass assignment), and `use Auditable` gives every
 * status transition (request/approve/second-approve/reject) a permanent
 * audit_logs row — including WHO made each transition, which is why this
 * model has no separate rejected_by/rejected_at columns of its own.
 *
 * `direction` carries the sign: 'decrease' subtracts `amount` from the
 * customer's net ledger position for `target_period` (a write-off/credit-in-
 * the-customer's-favor correction); 'increase' adds it (a billing-error
 * correction the other way, or clawing back a credit that should never have
 * been granted). `amount` itself is always stored positive — see
 * App\Services\ManuscriptCalculator's class doc for exactly how this is
 * folded into the net = previousNet + (billDue - income) ± adjustmentTotal
 * formula.
 *
 * `target` (2026-08-30 — see the design doc's credit-correction addendum)
 * picks WHICH side of `net = arrears - credit` the correction lands on:
 * 'arrears' (default, the original behavior) or 'credit'. A 'credit' target
 * with direction 'increase' claws credit back (`credit -= amount`, clamped at
 * 0); with 'decrease' it grants credit (`credit += amount`). It touches ONLY
 * the loose `manuscripts.credit` figure — never `prepaid_months_remaining` /
 * `prepaid_rate` (the draw-down model's own prepaid coverage), which is
 * explicitly out of scope. `credit_snapshot` is the credit-side counterpart
 * of `arrears_snapshot` — captured at request time so approve()'s staleness
 * re-check covers credit drift when `target = 'credit'`.
 *
 * `processed_at`/`processed_period` are the SAME idempotency mechanism as
 * `payments.processed_at`/`processed_period` — an adjustment is eligible for
 * manuscript calculation period P when `status = 'approved' AND
 * target_period = P AND (processed_period IS NULL OR processed_period = P)`.
 */
#[Fillable([
    'customer_id', 'target_period', 'direction', 'target', 'amount', 'reason_category', 'reason_note',
    'arrears_snapshot', 'credit_snapshot', 'status', 'approved_by', 'approved_at', 'second_approved_by', 'second_approved_at',
    'rejection_reason', 'complaint_id', 'processed_at', 'processed_period',
])]
#[RouteKey('uuid')]
class ArrearsAdjustment extends Model
{
    use Auditable, HasUuid;

    /**
     * Two-approver threshold default (companies.arrears_second_approval_threshold's
     * migration default) — used only as a defensive fallback when the single
     * Company settings row is somehow missing, mirroring
     * App\Services\CustomerStatusService::reconnectionFine()'s identical
     * fallback convention for reconnection_fine.
     */
    public const string DEFAULT_SECOND_APPROVAL_THRESHOLD = '20000.00';

    /**
     * Reason categories only ever paired with `target = 'credit'` — the
     * credit-correction fallback (2026-08-30 design-doc addendum). The
     * original arrears categories stay valid for `target = 'arrears'`; the
     * full allowed set lives in StoreArrearsAdjustmentRequest (no DB CHECK
     * constraint — see that migration).
     *
     * @var list<string>
     */
    public const array CREDIT_REASON_CATEGORIES = ['credit_correction', 'duplicate_credit', 'migration_credit_error'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'arrears_snapshot' => 'decimal:2',
            'credit_snapshot' => 'decimal:2',
            'approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * True when this correction targets the loose `manuscripts.credit`
     * figure rather than `total_arrears` (the default).
     */
    public function targetsCredit(): bool
    {
        return $this->target === 'credit';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // Cross-schema relations — User is pinned to the central `pgsql`
    // connection via #[Connection('pgsql')], so these resolve correctly
    // regardless of which tenant schema is currently active (same pattern
    // as Complaint::submittedBy()/assignedTo()/resolvedBy()).
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function secondApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approved_by');
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'pending_second_approval'], true);
    }
}
