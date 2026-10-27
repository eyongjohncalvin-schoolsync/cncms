<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\TechnicianFeePayout;
use App\Models\User;
use App\Support\CacheVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Technician motivation fees (D13, docs/plans/installation-and-promotions.md).
 *
 * The reconnection fine is recorded as a `technician_fee` payment
 * (CustomerStatusService::reconnectOne()), handed to the cashier with the
 * day's cash in the collector's remittance, and then paid out here to the
 * named technician: one cash-book outflow (kind technician_fee_payout,
 * document TF-…) from the cashier's own till, linked to exactly the fee
 * payments it settles. Pass-through money — no voucher approval chain, never
 * an expense, never income.
 *
 * A fee can be paid out once it is in the cashier's hands: not rejected, its
 * remittance received (so the cash really reached a till), and not already
 * paid out.
 */
class TechnicianFeeService
{
    public function __construct(
        private readonly CashBookService $cashBook,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @return Builder<Payment>
     */
    public function payableQuery(): Builder
    {
        return Payment::query()
            ->where('purpose', Payment::PURPOSE_TECHNICIAN_FEE)
            ->where('verification_status', '!=', 'rejected')
            ->whereNull('technician_fee_payout_id')
            ->whereHas('remittanceLine.remittance', fn (Builder $query) => $query->where('status', 'received'));
    }

    /**
     * @return Collection<int, Payment>
     */
    public function payable(): Collection
    {
        return $this->payableQuery()->with(['customer', 'recordedBy', 'remittanceLine.remittance'])->orderBy('created_at')->get();
    }

    /**
     * Pays the chosen collected fees to one technician from the cashier's
     * till. All-or-nothing: every chosen fee must still be payable.
     *
     * @param  list<string>  $paymentUuids
     */
    public function payOut(array $paymentUuids, string $technicianName, ?int $technicianUserId, ?string $note, User $cashier): TechnicianFeePayout
    {
        $paymentUuids = array_values(array_unique($paymentUuids));

        return DB::transaction(function () use ($paymentUuids, $technicianName, $technicianUserId, $note, $cashier): TechnicianFeePayout {
            $fees = $this->payableQuery()->whereIn('uuid', $paymentUuids)->lockForUpdate()->get();

            if ($fees->isEmpty() || $fees->count() !== count($paymentUuids)) {
                throw ValidationException::withMessages(['payments' => 'Some of these fees have already been paid out or have not been received by a cashier yet. Reload and try again.']);
            }

            $total = $fees->reduce(fn (string $sum, Payment $fee): string => bcadd($sum, (string) $fee->amount, 2), '0.00');
            $till = $this->cashBook->tillFor($cashier);

            CashAccount::query()->whereKey($till->id)->lockForUpdate()->first();

            if (bccomp($this->cashBook->balance($till), $total, 2) < 0) {
                throw ValidationException::withMessages(['payments' => "{$till->name} does not hold enough cash to pay these fees."]);
            }

            $payout = TechnicianFeePayout::query()->create([
                'number' => $this->documentNumbers->next(DocumentNumberService::TECHNICIAN_FEE),
                'technician_name' => $technicianName,
                'technician_user_id' => $technicianUserId,
                'amount' => $total,
                'cash_account_id' => $till->id,
                'paid_by' => $cashier->id,
                'paid_at' => now(),
                'note' => $note,
            ]);

            $movement = $this->cashBook->record($till, 'out', $total, CashMovement::KIND_TECHNICIAN_FEE_PAYOUT, $cashier, [
                'document_number' => $payout->number,
                'source' => $payout,
                'note' => "Technician fees paid to {$technicianName} ({$fees->count()} reconnection(s))",
            ]);

            $payout->update(['cash_movement_id' => $movement->id]);

            Payment::query()->whereIn('id', $fees->pluck('id'))->update(['technician_fee_payout_id' => $payout->id]);
            // A bulk update fires no model events.
            CacheVersion::bump(CacheVersion::PAYMENTS);

            return $payout;
        });
    }
}
