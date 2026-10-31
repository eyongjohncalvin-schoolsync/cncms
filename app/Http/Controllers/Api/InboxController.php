<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\RejectArrearsAdjustmentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveArrearsAdjustmentRequest;
use App\Http\Requests\RejectArrearsAdjustmentRequest;
use App\Models\ArrearsAdjustment;
use App\Models\Complaint;
use App\Models\PaymentReversalRequest;
use App\Models\Voucher;
use App\Services\ArrearsAdjustmentService;
use App\Services\ManagerInboxService;
use App\Services\PaymentReversalRequestService;
use App\Services\PaymentVerificationService;
use App\Services\TechnicianService;
use App\Services\VoucherService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The phone's manager inbox (docs/plans/mobile-manager-app.md, wave 1):
 * the list (App\Services\ManagerInboxService) and the decisions a manager
 * takes from it. Every action goes through the same policy and service as
 * its web twin (VoucherController, ArrearsAdjustmentController,
 * PaymentController::reverse, PaymentReversalRequestController::decline);
 * assigning a complaint is the existing POST complaints/{complaint}/assign.
 */
class InboxController extends Controller
{
    public function index(Request $request, ManagerInboxService $inbox, TenantContext $context): JsonResponse
    {
        $items = $inbox->items($request->user(), $context);

        return response()->json([
            'data' => $items,
            'counts' => [
                'total' => count($items),
                'now' => count(array_filter($items, fn (array $item) => $item['severity'] === 'now')),
            ],
        ]);
    }

    public function approveVoucher(Request $request, Voucher $voucher, VoucherService $vouchers): JsonResponse
    {
        $this->authorize('decide', $voucher);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $outcome = $vouchers->approve($voucher, $request->user(), $validated['note'] ?? null);

        return $this->done(match ($outcome) {
            'approved' => "{$voucher->number} approved — it can now be paid.",
            'recorded' => 'Your approval is recorded. The other stakeholders still need to approve.',
            default => 'The day is now over its payout limit — every stakeholder must approve, and they have been asked.',
        });
    }

    public function rejectVoucher(Request $request, Voucher $voucher, VoucherService $vouchers): JsonResponse
    {
        $this->authorize('decide', $voucher);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $vouchers->reject($voucher, $request->user(), $validated['reason']);

        return $this->done("{$voucher->number} rejected.");
    }

    public function approveArrears(ApproveArrearsAdjustmentRequest $request, ArrearsAdjustment $arrearsAdjustment, ArrearsAdjustmentService $adjustments): JsonResponse
    {
        $adjustment = $adjustments->approve($arrearsAdjustment, $request->user());

        return $this->done($adjustment->status === 'pending_second_approval'
            ? 'First approval recorded — it now needs a second, more senior approval.'
            : 'Arrears adjustment approved and applied.');
    }

    public function rejectArrears(RejectArrearsAdjustmentRequest $request, ArrearsAdjustment $arrearsAdjustment, ArrearsAdjustmentService $adjustments): JsonResponse
    {
        $adjustments->reject($arrearsAdjustment, RejectArrearsAdjustmentData::fromArray($request->validated()));

        return $this->done('Arrears adjustment rejected.');
    }

    /**
     * Reverse the payment the collector asked about. On the phone: no money
     * moved, or a refund bon; the web keeps the fuller options (record the
     * correct payment, free months instead).
     */
    public function reverse(Request $request, PaymentReversalRequest $reversalRequest, PaymentVerificationService $verifications): JsonResponse
    {
        $payment = $reversalRequest->payment;

        if (! $reversalRequest->isPending()) {
            throw ValidationException::withMessages(['reason' => 'This request was already decided.']);
        }

        $this->authorize('reverse', $payment);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'money' => ['nullable', 'string', 'in:none,refund'],
        ]);

        $result = DB::transaction(fn () => $verifications->reverse($payment, $validated['reason'], $request->user(), $validated['money'] ?? 'none'));

        return $this->done($result['was_pending'] ? 'Payment rejected.' : 'Payment reversed and its receipt voided.'
            .($result['refund_voucher'] !== null ? " Refund bon {$result['refund_voucher']->number} waits for approval." : ''));
    }

    public function declineReversal(Request $request, PaymentReversalRequest $reversalRequest, PaymentReversalRequestService $requests): JsonResponse
    {
        $this->authorize('declineReversal', $reversalRequest->payment);

        $validated = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        $requests->decline($reversalRequest, $request->user(), $validated['note']);

        return $this->done('Declined — the collector is told why.');
    }

    /**
     * Who to give a complaint to, best first (TechnicianService).
     */
    public function technicians(Complaint $complaint, TechnicianService $technicians): JsonResponse
    {
        $this->authorize('assign', Complaint::class);

        return response()->json(['data' => $technicians->suggestionsFor($complaint)]);
    }

    private function done(string $message): JsonResponse
    {
        return response()->json(['message' => $message]);
    }
}
