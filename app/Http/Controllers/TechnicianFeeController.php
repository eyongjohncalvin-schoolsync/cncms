<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTechnicianFeePayoutRequest;
use App\Models\Payment;
use App\Models\TechnicianFeePayout;
use App\Services\TechnicianFeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Technician motivation fees (D13, docs/plans/installation-and-promotions.md):
 * the fees collected at reconnection and received by a cashier, waiting to be
 * paid to the technician, plus the payouts already made. Seen by anyone with
 * the cash book (`cash.view`) or a till (`cash.receive`); paying out needs a
 * till. See App\Services\TechnicianFeeService.
 */
class TechnicianFeeController extends Controller
{
    public function __construct(
        private readonly TechnicianFeeService $fees,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('cash.view') || $request->user()->can('cash.receive'), 403);

        return Inertia::render('Cash/TechnicianFees', [
            'fees' => $this->fees->payable()->map(fn (Payment $fee): array => [
                'uuid' => $fee->uuid,
                'customer_name' => $fee->customer?->name,
                'amount' => (string) $fee->amount,
                'collected_by_name' => $fee->recordedBy?->name,
                'remittance_number' => $fee->remittanceLine?->remittance?->number,
                'recorded_at' => $fee->created_at?->toIso8601String(),
            ])->values()->all(),
            'payouts' => TechnicianFeePayout::query()->with('paidBy')->withCount('payments')->latest('paid_at')->limit(20)->get()
                ->map(fn (TechnicianFeePayout $payout): array => [
                    'uuid' => $payout->uuid,
                    'number' => $payout->number,
                    'technician_name' => $payout->technician_name,
                    'amount' => (string) $payout->amount,
                    'fees_count' => $payout->payments_count,
                    'paid_by_name' => $payout->paidBy?->name,
                    'paid_at' => $payout->paid_at?->toIso8601String(),
                    'note' => $payout->note,
                ])->values()->all(),
            'can_pay' => $request->user()->can('cash.receive'),
        ]);
    }

    public function store(StoreTechnicianFeePayoutRequest $request): RedirectResponse
    {
        $payout = $this->fees->payOut(
            $request->validated('payments'),
            trim((string) $request->validated('technician_name')),
            null,
            $request->validated('note'),
            $request->user(),
        );

        return back()->with('success', "{$payout->number}: ".number_format((float) $payout->amount, 0, '.', ' ')." FCFA paid to {$payout->technician_name} from your till.");
    }
}
