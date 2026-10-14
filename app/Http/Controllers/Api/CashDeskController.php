<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashHandover;
use App\Services\CashDeskService;
use App\Services\CashLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The cash desk on the phone: my cash, handing cash over and confirming
 * cash handed to me (two phones, one code), the daily blind count and
 * deposits. Online-only — every step is a two-person check made live.
 */
class CashDeskController extends Controller
{
    public function __construct(
        private readonly CashDeskService $desk,
        private readonly CashLedgerService $ledger,
    ) {}

    public function me(Request $request): JsonResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        return response()->json(['data' => $this->desk->summaryFor($request->user())]);
    }

    public function targets(Request $request): JsonResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        return response()->json([
            'data' => $this->desk->handoverTargets($request->user())->map(fn (CashAccount $account): array => [
                'uuid' => $account->uuid,
                'type' => $account->type,
                'name' => $account->name,
                'holder' => $account->holder?->name,
            ])->values()->all(),
            'deposit_accounts' => CashAccount::query()->where('is_active', true)->whereNotIn('type', CashAccount::CASH_TYPES)->orderBy('name')->get(['uuid', 'type', 'name']),
        ]);
    }

    public function startHandover(Request $request): JsonResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $validated = $request->validate([
            'to_account_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:255'],
            'local_uuid' => ['nullable', 'uuid'],
        ]);

        $to = CashAccount::query()->where('uuid', $validated['to_account_uuid'])->firstOrFail();

        $handover = $this->desk->startHandover(
            $request->user(), $to, (string) $validated['amount'], $validated['denominations'] ?? null, $validated['note'] ?? null, $validated['local_uuid'] ?? null,
        );

        return response()->json(['data' => $this->desk->handoverRow($handover->load(['fromAccount', 'toAccount', 'initiator']), withCode: true)], 201);
    }

    public function confirmHandover(Request $request, CashHandover $handover): JsonResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'received_amount' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
        ]);

        $handover = $this->desk->confirmHandover($handover, $request->user(), $validated['code'], (string) $validated['received_amount'], $validated['denominations'] ?? null);

        return response()->json(['data' => $this->desk->handoverRow($handover->load(['fromAccount', 'toAccount', 'initiator']))]);
    }

    public function cancelHandover(Request $request, CashHandover $handover): JsonResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $handover = $this->desk->cancelHandover($handover, $request->user());

        return response()->json(['data' => $this->desk->handoverRow($handover->load(['fromAccount', 'toAccount', 'initiator']))]);
    }

    public function submitClose(Request $request): JsonResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $validated = $request->validate([
            'denominations' => ['present', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
            // The phone refuses to close while it still has unsynced
            // records; the server double-checks.
            'unsynced_count' => ['nullable', 'integer', 'max:0'],
        ], ['unsynced_count.max' => 'Sync your phone first — some payments or expenses have not reached the office yet.']);

        $close = $this->desk->submitClose($request->user(), $validated['denominations'], $validated['note'] ?? null);

        return response()->json(['data' => $this->desk->closeRow($close->load(['account', 'closer']))], 201);
    }

    public function deposit(Request $request): JsonResponse
    {
        $this->authorize('useOwnTill', CashAccount::class);

        $validated = $request->validate([
            'to_account_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'],
            'reference' => ['required', 'string', 'max:80', Rule::unique('cash_deposits', 'reference')],
            'slip' => ['required', 'image', 'max:6144'],
        ]);

        $user = $request->user();
        $to = CashAccount::query()->where('uuid', $validated['to_account_uuid'])->firstOrFail();
        $from = $this->ledger->tillFor($user);

        $deposit = $this->desk->deposit($user, $from, $to, (string) $validated['amount'], $validated['reference'], $request->file('slip'));

        return response()->json(['data' => ['uuid' => $deposit->uuid, 'status' => $deposit->status, 'amount' => (string) $deposit->amount]], 201);
    }
}
