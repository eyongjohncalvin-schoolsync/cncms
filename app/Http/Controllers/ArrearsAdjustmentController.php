<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\ArrearsAdjustmentData;
use App\DataTransferObjects\RejectArrearsAdjustmentData;
use App\Http\Requests\ApproveArrearsAdjustmentRequest;
use App\Http\Requests\RejectArrearsAdjustmentRequest;
use App\Http\Requests\StoreArrearsAdjustmentRequest;
use App\Models\ArrearsAdjustment;
use App\Services\ArrearsAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

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
