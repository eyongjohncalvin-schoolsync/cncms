<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CashTransfer;
use App\Services\CashTransferService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Money waiting for someone other than the sender (owner, 2026-10-28,
 * wave 3) — a handover into another person's till, a bank deposit waiting
 * for its confirmers. Who may act is checked in CashTransferService (the
 * person handed to, the sender, a confirmer who is not the depositor).
 */
class CashTransferController extends Controller
{
    public function __construct(
        private readonly CashTransferService $transfers,
    ) {}

    public function accept(Request $request, CashTransfer $cashTransfer): RedirectResponse
    {
        $validated = $request->validate([
            'counted' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $transfer = $this->transfers->acceptHandover($cashTransfer, number_format((float) $validated['counted'], 2, '.', ''), $request->user(), $validated['note'] ?? null);

        return back()->with('success', bccomp((string) $transfer->received_amount, (string) $transfer->amount, 2) === 0
            ? "{$transfer->number}: accepted into your till."
            : "{$transfer->number}: accepted what you counted — the rest is {$transfer->sentBy?->name}'s shortage.");
    }

    public function refuse(Request $request, CashTransfer $cashTransfer): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $transfer = $this->transfers->refuseHandover($cashTransfer, $validated['reason'], $request->user());

        return back()->with('success', "{$transfer->number}: refused — back in {$transfer->sentBy?->name}'s till.");
    }

    public function recall(Request $request, CashTransfer $cashTransfer): RedirectResponse
    {
        $transfer = $this->transfers->recall($cashTransfer, $request->user());

        return back()->with('success', "{$transfer->number}: back in your till.");
    }

    public function confirm(Request $request, CashTransfer $cashTransfer): RedirectResponse
    {
        $transfer = $this->transfers->confirmDeposit($cashTransfer, $request->user());

        return back()->with('success', $transfer->status === CashTransfer::STATUS_RECEIVED
            ? "{$transfer->number}: confirmed — the bank account is credited."
            : "{$transfer->number}: your confirmation is recorded — waiting for the next person.");
    }

    public function refuseDeposit(Request $request, CashTransfer $cashTransfer): RedirectResponse
    {
        $validated = $request->validate([
            'found' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $transfer = $this->transfers->refuseDeposit($cashTransfer, number_format((float) $validated['found'], 2, '.', ''), $validated['reason'], $request->user());

        return back()->with('success', "{$transfer->number}: refused — what is missing is {$transfer->sentBy?->name}'s shortage.");
    }

    /** The deposit slip photo, for whoever may check deposits or sent it. */
    public function slip(Request $request, CashTransfer $cashTransfer): StreamedResponse
    {
        abort_unless($cashTransfer->sent_by === $request->user()->id || app(TenantContext::class)->can('cash.view'), 403);

        $disk = $this->transfers->disk();
        abort_if($cashTransfer->slip_photo_path === null || ! Storage::disk($disk)->exists($cashTransfer->slip_photo_path), 404);

        return Storage::disk($disk)->response($cashTransfer->slip_photo_path);
    }
}
