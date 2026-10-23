<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\Voucher;
use App\Services\CashBookService;
use App\Services\RemittanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The daily income form for the mobile app (docs/plans/cash-controls.md,
 * wave 4): what the collector should hand over, and submitting it. Online
 * only — a handover happens face to face at the office anyway. Receiving
 * stays on the web (the cashier's desk).
 */
class RemittanceController extends Controller
{
    public function __construct(
        private readonly RemittanceService $remittances,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $this->authorize('create', Remittance::class);

        $preview = $this->remittances->preview($request->user());

        return response()->json(['data' => [
            'totals' => $preview['totals'],
            'count' => $preview['payments']->count(),
            'oldest_at' => $preview['oldest_at'],
            // Bons already paid out of these collections
            // (docs/plans/expenditures-and-spending-controls.md, item 2): the
            // cashier pays them at the handover, so the drawer should hold
            // cash_to_hand_over, not totals.cash.
            'bons' => $preview['bons']->map(fn (Voucher $bon): array => [
                'uuid' => $bon->uuid,
                'number' => $bon->number,
                'payee_name' => $bon->payee_name,
                'amount' => (string) $bon->amount,
            ])->values()->all(),
            'bons_total' => $preview['bons_total'],
            'cash_to_hand_over' => $preview['cash_to_hand_over'],
            'payments' => $preview['payments']->map(fn (Payment $payment): array => [
                'uuid' => $payment->uuid,
                'customer_name' => $payment->customer?->name,
                'amount' => (string) $payment->amount,
                'method' => $payment->method ?? 'cash',
                'method_reference' => $payment->method_reference,
                'receipt_number' => $payment->receipt?->receipt_number,
            ])->values()->all(),
            'denominations' => CashBookService::DENOMINATIONS,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('create', Remittance::class);

        return response()->json(['data' => Remittance::query()
            ->where('collector_user_id', $request->user()->id)
            ->latest('submitted_at')
            ->limit(20)
            ->get()
            ->map(fn (Remittance $remittance): array => $this->shape($remittance))
            ->values()
            ->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Remittance::class);

        $validated = $request->validate([
            'declared_cash' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $remittance = $this->remittances->submit(
            $request->user(),
            number_format((float) $validated['declared_cash'], 2, '.', ''),
            CashBookService::normalizeDenominations($validated['denominations'] ?? null),
            $validated['note'] ?? null,
        );

        return response()->json(['data' => $this->shape($remittance)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Remittance $remittance): array
    {
        return [
            'uuid' => $remittance->uuid,
            'number' => $remittance->number,
            'status' => $remittance->status,
            'expected_cash' => (string) $remittance->expected_cash,
            'expected_mtn' => (string) $remittance->expected_mtn,
            'expected_orange' => (string) $remittance->expected_orange,
            'declared_cash' => (string) $remittance->declared_cash,
            'received_cash' => $remittance->received_cash !== null ? (string) $remittance->received_cash : null,
            'cash_variance' => $remittance->cash_variance !== null ? (string) $remittance->cash_variance : null,
            'shortage_status' => $remittance->shortage_status,
            'submitted_at' => $remittance->submitted_at?->toIso8601String(),
            'received_at' => $remittance->received_at?->toIso8601String(),
        ];
    }
}
