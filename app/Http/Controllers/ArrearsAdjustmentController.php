<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\ArrearsAdjustmentData;
use App\DataTransferObjects\RejectArrearsAdjustmentData;
use App\Http\Requests\ApproveArrearsAdjustmentRequest;
use App\Http\Requests\BulkApproveArrearsAdjustmentsRequest;
use App\Http\Requests\RejectArrearsAdjustmentRequest;
use App\Http\Requests\StoreArrearsAdjustmentRequest;
use App\Models\ArrearsAdjustment;
use App\Services\ArrearsAdjustmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Arrears Adjustment maker-checker workflow's mutating actions. There is
 * deliberately no dedicated index/show page here — the request form lives as
 * a modal on Customers/Show.tsx (that controller embeds the customer's
 * recent adjustments directly), and the review/browse surface is the Audit
 * Log page's "Arrears Adjustments" sub-tab (App\Http\Controllers\
 * AuditLogController), so approve()/reject() below redirect back() to
 * wherever the action was triggered from rather than to a page this
 * controller owns.
 */
class ArrearsAdjustmentController extends Controller
{
    public function __construct(
        private readonly ArrearsAdjustmentService $adjustments,
    ) {}

    /**
     * The review queue — moved off the Audit Log's `?view=arrears_adjustments`
     * sub-tab onto its own page (2026-09-22): that tab's pagination links
     * dropped the `view` param, so "Next" fell back to All Activity.
     * withQueryString() keeps the status filter on every page link here.
     */
    public function index(Request $request): Response
    {
        $this->authorize('review', ArrearsAdjustment::class);

        $filters = $request->only(['status']);
        $paginated = $this->adjustments->list($filters, 25)->withQueryString();

        $paginated->through(fn (ArrearsAdjustment $adjustment): array => [
            'uuid' => $adjustment->uuid,
            'target_period' => $adjustment->target_period,
            'direction' => $adjustment->direction,
            'target' => $adjustment->target,
            'amount' => $adjustment->amount,
            'arrears_snapshot' => $adjustment->arrears_snapshot,
            'credit_snapshot' => $adjustment->credit_snapshot,
            'reason_category' => $adjustment->reason_category,
            'reason_note' => $adjustment->reason_note,
            'status' => $adjustment->status,
            'customer_uuid' => $adjustment->customer?->uuid,
            'customer_name' => $adjustment->customer?->name,
            'requested_by_name' => $adjustment->requestedBy?->name,
            'approved_by_name' => $adjustment->approvedBy?->name,
            'second_approved_by_name' => $adjustment->secondApprovedBy?->name,
            'rejection_reason' => $adjustment->rejection_reason,
            'created_at' => $adjustment->created_at?->toIso8601String(),
            'can_approve' => $request->user()->can('approve', $adjustment),
            'can_reject' => $request->user()->can('reject', $adjustment),
            // Drives the "approve your own request?" confirm step — the super
            // self-approval carve-out (ArrearsAdjustmentPolicy) is allowed,
            // but never silent.
            'is_own_request' => $adjustment->requested_by === $request->user()->id,
        ]);

        $array = $paginated->toArray();

        return Inertia::render('ArrearsAdjustments/Index', [
            'filters' => $filters,
            'stats' => $this->adjustments->dashboard(),
            'adjustments' => [
                'data' => $array['data'],
                'links' => $array['links'],
                'meta' => [
                    'current_page' => $array['current_page'],
                    'per_page' => $array['per_page'],
                    'total' => $array['total'],
                    'last_page' => $array['last_page'],
                ],
            ],
        ]);
    }

    public function store(StoreArrearsAdjustmentRequest $request): RedirectResponse
    {
        $adjustment = $this->adjustments->create(ArrearsAdjustmentData::fromArray($request->validated()), $request->user()->id);

        return back()->with('success', "Arrears adjustment requested for {$adjustment->customer->name}.");
    }

    public function approve(ApproveArrearsAdjustmentRequest $request, ArrearsAdjustment $arrearsAdjustment): RedirectResponse
    {
        try {
            $adjustment = $this->adjustments->approve($arrearsAdjustment, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        $message = $adjustment->status === 'pending_second_approval'
            ? 'First approval recorded — this adjustment now needs a second, more senior approval before it takes effect.'
            : 'Arrears adjustment approved and applied.';

        return back()->with('success', $message);
    }

    /**
     * Bulk counterpart of approve(), same idea as PaymentController::
     * bulkVerify(): each selected row goes through the exact single-row
     * path — the policy (maker≠checker, second-approval stage) and the
     * service's row-locked staleness re-check. A row that fails either is
     * skipped and counted, never fatal, so one bad row can't block the rest.
     * Rows for the same customer+period are approved in turn, so a later one
     * can legitimately turn stale once an earlier one moves the figure.
     */
    public function bulkApprove(BulkApproveArrearsAdjustmentsRequest $request): RedirectResponse
    {
        $approvedCount = 0;
        $secondApprovalCount = 0;
        $skippedCount = 0;

        foreach ($request->validated('adjustment_uuids') as $uuid) {
            try {
                $adjustment = $this->adjustments->findOrFail($uuid);
            } catch (ModelNotFoundException) {
                $skippedCount++;

                continue;
            }

            if ($request->user()->cannot('approve', $adjustment)) {
                $skippedCount++;

                continue;
            }

            try {
                $adjustment = $this->adjustments->approve($adjustment, $request->user());
            } catch (ValidationException) {
                $skippedCount++;

                continue;
            }

            if ($adjustment->status === 'pending_second_approval') {
                $secondApprovalCount++;
            } else {
                $approvedCount++;
            }
        }

        $parts = [];

        if ($approvedCount > 0) {
            $parts[] = $approvedCount === 1 ? '1 adjustment approved and applied.' : "{$approvedCount} adjustments approved and applied.";
        }

        if ($secondApprovalCount > 0) {
            $parts[] = $secondApprovalCount === 1
                ? '1 now needs a second approval.'
                : "{$secondApprovalCount} now need a second approval.";
        }

        if ($skippedCount > 0) {
            $parts[] = $skippedCount === 1
                ? '1 was skipped (not yours to approve, already decided, or its figure changed).'
                : "{$skippedCount} were skipped (not yours to approve, already decided, or their figure changed).";
        }

        $acted = $approvedCount + $secondApprovalCount > 0;

        return back()->with($acted ? 'success' : 'error', $acted ? implode(' ', $parts) : 'Nothing was approved. '.implode(' ', $parts));
    }

    public function reject(RejectArrearsAdjustmentRequest $request, ArrearsAdjustment $arrearsAdjustment): RedirectResponse
    {
        try {
            $this->adjustments->reject($arrearsAdjustment, RejectArrearsAdjustmentData::fromArray($request->validated()));
        } catch (ValidationException $e) {
            // Symmetric with approve() above: a request decided between this
            // controller's policy check and the service's own row-locked
            // isPending() re-check (see ArrearsAdjustmentService::reject())
            // surfaces as a friendly flash, not a raw validation-error bag.
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Arrears adjustment rejected.');
    }
}
