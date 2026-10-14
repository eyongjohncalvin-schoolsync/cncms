<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceEntry;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening balances: onboarding a company whose customer balances only exist
 * on paper. Staff enter each customer's starting position — what they owe
 * before the go-live month, and any advance (money and/or prepaid months) —
 * as a draft batch, submit it, and someone else approves it (maker-checker,
 * same identity rules as arrears adjustments; see OpeningBalanceBatchPolicy).
 *
 * Approval writes each customer's FIRST manuscript, for the month before
 * go-live, so the first manuscript:calculate for the go-live month carries
 * the balances forward through the normal engine:
 *
 *   owed           → total_arrears
 *   advance money  → credit (drawn down at the current bill)
 *   advance months → prepaid_months_remaining, locked at the customer's
 *                    current bill (prepaid_rate) — the draw-down model a
 *                    `months` payment uses (references/prepayment-drawdown.md)
 *
 * Only customers with no manuscript yet can be given a starting balance.
 * Once a bill run has calculated the go-live month for any customer in an
 * approved batch it is locked; later corrections go through arrears
 * adjustments.
 */
class OpeningBalanceService
{
    /** Hard ceiling per field — far above any real cable bill, catches typos like an extra zero or two. */
    public const string MAX_AMOUNT = '10000000.00';

    public const int MAX_ADVANCE_MONTHS = 120;

    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The batch currently being prepared or awaiting approval, if any —
     * there is at most one at a time.
     */
    public function openBatch(): ?OpeningBalanceBatch
    {
        return OpeningBalanceBatch::query()
            ->whereIn('status', ['draft', 'pending_approval'])
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, OpeningBalanceBatch>
     */
    public function approvedBatches(): Collection
    {
        return OpeningBalanceBatch::query()
            ->where('status', 'approved')
            ->withCount('entries')
            ->with(['submittedBy', 'approvedBy'])
            ->latest('approved_at')
            ->get();
    }

    public function start(string $goLivePeriod, User $actor): OpeningBalanceBatch
    {
        $this->assertValidGoLivePeriod($goLivePeriod);

        return DB::transaction(function () use ($goLivePeriod, $actor): OpeningBalanceBatch {
            if (OpeningBalanceBatch::query()->whereIn('status', ['draft', 'pending_approval'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'go_live_period' => ['An opening-balances batch is already in progress. Finish or discard it first.'],
                ]);
            }

            return OpeningBalanceBatch::query()->create([
                'go_live_period' => $goLivePeriod,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
        });
    }

    public function changeGoLivePeriod(OpeningBalanceBatch $batch, string $goLivePeriod): OpeningBalanceBatch
    {
        $this->assertDraft($batch);
        $this->assertValidGoLivePeriod($goLivePeriod);

        $batch->update(['go_live_period' => $goLivePeriod]);

        return $batch;
    }

    /**
     * Upserts the ledger rows the screen autosaves. A row whose three
     * figures are all blank removes that customer's entry ("not entered
     * yet"); zeros are a real entry ("nothing owed").
     *
     * @param  list<array{customer_uuid: string, arrears: ?string, advance_amount: ?string, advance_months: ?int, note: ?string}>  $rows
     * @return array{saved: int, removed: int}
     */
    public function saveEntries(OpeningBalanceBatch $batch, array $rows): array
    {
        $this->assertDraft($batch);

        $eligible = $this->customers->withoutManuscripts(array_column($rows, 'customer_uuid'))->keyBy('uuid');
        $unknown = collect($rows)->pluck('customer_uuid')->reject(fn (string $uuid): bool => $eligible->has($uuid));

        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'rows' => ['Some customers can no longer be given a starting balance (they already have billing history, or are outside your branch). Reload the page.'],
            ]);
        }

        $saved = 0;
        $removed = 0;

        DB::transaction(function () use ($batch, $rows, $eligible, &$saved, &$removed): void {
            foreach ($rows as $row) {
                $customer = $eligible->get($row['customer_uuid']);
                $isBlank = $row['arrears'] === null && $row['advance_amount'] === null && $row['advance_months'] === null;

                if ($isBlank) {
                    $removed += OpeningBalanceEntry::query()
                        ->where('opening_balance_batch_id', $batch->id)
                        ->where('customer_id', $customer->id)
                        ->delete();

                    continue;
                }

                OpeningBalanceEntry::query()->updateOrCreate(
                    ['opening_balance_batch_id' => $batch->id, 'customer_id' => $customer->id],
                    [
                        'arrears' => $row['arrears'] ?? '0',
                        'advance_amount' => $row['advance_amount'] ?? '0',
                        'advance_months' => $row['advance_months'] ?? 0,
                        'note' => $row['note'] ?? null,
                    ],
                );
                $saved++;
            }

            $batch->touch();
        });

        return ['saved' => $saved, 'removed' => $removed];
    }

    public function submit(OpeningBalanceBatch $batch, User $actor): OpeningBalanceBatch
    {
        $this->assertDraft($batch);

        if (! $batch->entries()->exists()) {
            throw ValidationException::withMessages([
                'batch' => ['Enter at least one customer\'s balance before submitting.'],
            ]);
        }

        $batch->update([
            'status' => 'pending_approval',
            'submitted_by' => $actor->id,
            'submitted_at' => Carbon::now(),
        ]);

        $this->notifyApprovers($batch, $actor);

        return $batch;
    }

    /**
     * Writes one starting manuscript per entry, for the month before
     * go-live. Refuses (changing nothing) if any customer has since gained
     * billing history — their balance can no longer be set this way.
     */
    public function approve(OpeningBalanceBatch $batch, User $actor): OpeningBalanceBatch
    {
        $result = DB::transaction(function () use ($batch, $actor): OpeningBalanceBatch {
            // Re-read under a lock: two approvers clicking at once must not
            // both write the starting manuscripts.
            $batch = OpeningBalanceBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if (! $batch->isPendingApproval()) {
                throw ValidationException::withMessages(['batch' => ['This batch is no longer awaiting approval.']]);
            }

            $entries = $batch->entries()->with('customer')->get();
            $conflicts = Customer::query()
                ->whereIn('id', $entries->pluck('customer_id'))
                ->whereHas('manuscripts')
                ->pluck('name');

            if ($conflicts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'batch' => ['These customers already have billing history, so their starting balance can no longer be set here: '.$conflicts->implode(', ').'. Reject the batch, remove them, and resubmit.'],
                ]);
            }

            $baselinePeriod = $batch->baselinePeriod();

            foreach ($entries as $entry) {
                Manuscript::query()->create([
                    'customer_id' => $entry->customer_id,
                    'opening_balance_batch_id' => $batch->id,
                    ...self::manuscriptAttributes($entry, (string) $entry->customer->bill, $baselinePeriod),
                ]);
            }

            $batch->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => Carbon::now(),
            ]);

            return $batch;
        });

        $this->notifySubmitter($result, 'opening_balances.approved', 'Opening balances approved',
            "The opening balances for {$this->monthLabel($result->go_live_period)} were approved and are now each customer's starting position.");

        return $result;
    }

    public function reject(OpeningBalanceBatch $batch, User $actor, string $reason): OpeningBalanceBatch
    {
        if (! $batch->isPendingApproval()) {
            throw ValidationException::withMessages(['batch' => ['This batch is no longer awaiting approval.']]);
        }

        $batch->update([
            'status' => 'draft',
            'rejected_by' => $actor->id,
            'rejected_at' => Carbon::now(),
            'rejection_reason' => $reason,
        ]);

        $this->notifySubmitter($batch, 'opening_balances.rejected', 'Opening balances sent back',
            "The opening balances for {$this->monthLabel($batch->go_live_period)} were sent back for correction: {$reason}");

        return $batch;
    }

    /**
     * Back to draft, removing the starting manuscripts approval wrote —
     * only while no bill run has used them yet.
     */
    public function reopen(OpeningBalanceBatch $batch): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($batch): OpeningBalanceBatch {
            $batch = OpeningBalanceBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if (! $batch->isApproved()) {
                throw ValidationException::withMessages(['batch' => ['Only an approved batch can be reopened.']]);
            }

            if ($this->isLocked($batch)) {
                throw ValidationException::withMessages([
                    'batch' => ['A bill run has already used these balances, so they are locked. Correct individual customers with an arrears adjustment instead.'],
                ]);
            }

            if ($this->openBatch() !== null) {
                throw ValidationException::withMessages([
                    'batch' => ['Another opening-balances batch is in progress. Finish or discard it first.'],
                ]);
            }

            // Model deletes (not a bulk query) so each removal is audited.
            $batch->manuscripts()->get()->each->delete();

            $batch->update([
                'status' => 'draft',
                'submitted_by' => null,
                'submitted_at' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $batch;
        });
    }

    public function discard(OpeningBalanceBatch $batch): void
    {
        $this->assertDraft($batch);

        $batch->delete();
    }

    /**
     * Locked once any customer in the batch has a manuscript for the
     * go-live month or later — i.e. a bill run has carried these balances
     * forward.
     */
    public function isLocked(OpeningBalanceBatch $batch): bool
    {
        return $batch->isApproved()
            && Manuscript::query()
                ->whereIn('customer_id', $batch->entries()->select('customer_id'))
                ->where('period', '>=', $batch->go_live_period)
                ->exists();
    }

    /**
     * The starting manuscript's figures for one entry. `total_bill` is what
     * the customer owes at go-live before the new month's charge: debt
     * minus loose advance money, never below zero.
     *
     * @return array{bill: string, total_arrears: string, credit: string, total_bill: string, prepaid_months_remaining: int, prepaid_rate: ?string, period: string, payment_expiration: null, command_run_id: null}
     */
    public static function manuscriptAttributes(OpeningBalanceEntry $entry, string $currentBill, string $baselinePeriod): array
    {
        $arrears = bcadd((string) $entry->arrears, '0', 2);
        $credit = bcadd((string) $entry->advance_amount, '0', 2);
        $months = (int) $entry->advance_months;
        $owed = bcsub($arrears, $credit, 2);

        return [
            'bill' => bcadd($currentBill, '0', 2),
            'total_arrears' => $arrears,
            'credit' => $credit,
            'total_bill' => bccomp($owed, '0', 2) < 0 ? '0.00' : $owed,
            'prepaid_months_remaining' => $months,
            'prepaid_rate' => $months > 0 ? bcadd($currentBill, '0', 2) : null,
            'period' => $baselinePeriod,
            'payment_expiration' => null,
            'command_run_id' => null,
        ];
    }

    private function assertDraft(OpeningBalanceBatch $batch): void
    {
        if (! $batch->isDraft()) {
            throw ValidationException::withMessages([
                'batch' => ['This batch has been submitted and can no longer be edited.'],
            ]);
        }
    }

    private function assertValidGoLivePeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw ValidationException::withMessages(['go_live_period' => ['The first billing month must be in YYYY-MM format.']]);
        }

        $earliest = Carbon::now()->subMonthNoOverflow()->format('Y-m');
        $latest = Carbon::now()->addMonthsNoOverflow(12)->format('Y-m');

        if ($period < $earliest || $period > $latest) {
            throw ValidationException::withMessages([
                'go_live_period' => ["The first billing month must be between {$this->monthLabel($earliest)} and {$this->monthLabel($latest)}."],
            ]);
        }
    }

    private function notifyApprovers(OpeningBalanceBatch $batch, User $submitter): void
    {
        $roles = Role::query()
            ->where('is_super', true)
            ->orWhereHas('permissions', fn ($query) => $query->where('permission', 'opening_balances.approve'))
            ->pluck('name');

        $count = $batch->entries()->count();

        foreach ($roles as $role) {
            $this->notifications->broadcastToRole(
                $role,
                'opening_balances.submitted',
                'info',
                'Opening balances awaiting approval',
                "{$submitter->name} submitted starting balances for {$count} customer(s), first billing month {$this->monthLabel($batch->go_live_period)}.",
                '/opening-balances',
                'opening_balance_batch',
                $batch->uuid,
            );
        }
    }

    private function notifySubmitter(OpeningBalanceBatch $batch, string $type, string $title, string $body): void
    {
        $submitter = $batch->submittedBy ?? ($batch->submitted_by ? User::query()->find($batch->submitted_by) : null);

        if ($submitter === null) {
            return;
        }

        $this->notifications->broadcastToUser($submitter, $type, 'info', $title, $body, '/opening-balances', 'opening_balance_batch', $batch->uuid);
    }

    private function monthLabel(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period)->format('F Y');
    }
}
