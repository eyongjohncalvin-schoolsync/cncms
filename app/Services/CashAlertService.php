<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\CashAccount;
use App\Models\CashDayClose;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\ReceiptLeaf;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Models\SyncQueue;
use App\Models\User;
use App\Models\Voucher;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Everything in the cash controls that needs a person to look at it
 * (docs/plans/cash-controls.md, wave 6). Computed on demand from the
 * records themselves — nothing is stored, so an alert disappears the moment
 * its cause is fixed. Feeds the Cash alerts page, the dashboard counts and
 * the daily email digest.
 *
 * Each alert: key, severity (danger|warning), title, what to do, and the
 * individual items with a link to each.
 */
class CashAlertService
{
    /** Days of history a "recent" check looks back over. */
    private const int LOOKBACK_DAYS = 30;

    /** An approved voucher nobody has paid after this many days. */
    private const int UNPAID_VOUCHER_DAYS = 3;

    /** A paid voucher still without its signed copy after this many days. */
    private const int MISSING_SIGNATURE_DAYS = 2;

    /** Money sent between branches and not received after this many days. */
    private const int TRANSFER_IN_TRANSIT_DAYS = 3;

    public function __construct(
        private readonly SpendingControlService $spending,
    ) {}

    /**
     * @return list<array{key: string, severity: string, title: string, advice: string, items: list<array{label: string, detail: string, amount: ?string, link: ?string}>}>
     */
    public function alerts(): array
    {
        $deadlineHours = (int) (Company::cached()?->remittance_deadline_hours ?? 24);

        return array_values(array_filter([
            $this->cashNotHandedOver($deadlineHours),
            $this->phonePaymentsNotSynced(),
            $this->possibleDuplicatePayments(),
            $this->lateRegistrationsPaidSomeone(),
            $this->handoversNotReceived($deadlineHours),
            $this->openShortages(),
            $this->collectorCredits(),
            $this->walletLinesNotFound(),
            $this->receiptsScannedBeforeRecording($deadlineHours),
            $this->unaccountedReceiptNumbers(),
            $this->tillsNotClosed(),
            $this->closeDifferences(),
            $this->advancesNotJustified(),
            $this->approvedVouchersUnpaid(),
            $this->vouchersWithoutSignedCopy(),
            $this->monthInDeficit(),
            $this->branchTransfersInTransit(),
            $this->branchesNotSendingToHeadquarters(),
        ], fn (?array $alert): bool => $alert !== null && $alert['items'] !== []));
    }

    /**
     * Money sent between branches and not received after
     * TRANSFER_IN_TRANSIT_DAYS (branches v2 §4.7) — the receiving branch must
     * confirm it or refuse it. Each branch sees what it sent or receives.
     */
    private function branchTransfersInTransit(): array
    {
        return $this->alert('branch_transfers_in_transit', 'warning', 'Money in transit between branches', 'Sent more than '.self::TRANSFER_IN_TRANSIT_DAYS.' days ago and not yet received — the receiving branch must receive or refuse it.',
            BranchTransfer::query()->visible()->with(['fromBranch:id,name', 'toBranch:id,name'])
                ->where('status', BranchTransfer::STATUS_SENT)
                ->where('sent_at', '<', now()->subDays(self::TRANSFER_IN_TRANSIT_DAYS))
                ->get()
                ->map(fn (BranchTransfer $transfer): array => [
                    'label' => "{$transfer->number} — {$transfer->fromBranch?->name} → {$transfer->toBranch?->name}",
                    'detail' => 'Sent '.$transfer->sent_at?->format('d M'),
                    'amount' => (string) $transfer->amount,
                    'link' => '/cash/branch-transfers',
                ])->all());
    }

    /**
     * A branch expected to send money to headquarters weekly or monthly
     * (branches.sends_to_hq) that has not done so within that time — for
     * headquarters only (not a fenced branch's own alert list).
     */
    private function branchesNotSendingToHeadquarters(): ?array
    {
        $headquartersId = Branch::headquartersId();

        if ($headquartersId === null || (TenantContext::currentBranchId() !== null && TenantContext::currentBranchId() !== $headquartersId)) {
            return null;
        }

        $expected = Branch::allMemoized()->filter(fn (Branch $branch): bool => ! $branch->is_headquarters && $branch->sends_to_hq !== 'off');

        if ($expected->isEmpty()) {
            return null;
        }

        $lastSent = BranchTransfer::query()->where('to_branch_id', $headquartersId)->where('status', '!=', BranchTransfer::STATUS_REJECTED)
            ->whereIn('from_branch_id', $expected->keys())
            ->groupBy('from_branch_id')->selectRaw('from_branch_id, max(sent_at) as last_sent')
            ->pluck('last_sent', 'from_branch_id');

        return $this->alert('branches_not_sending', 'warning', 'Branches that have not sent money to headquarters', 'Each of these branches is expected to send its money to headquarters on a schedule.',
            $expected->filter(function (Branch $branch) use ($lastSent): bool {
                $due = $branch->sends_to_hq === 'weekly' ? now()->subWeek() : now()->subMonth();
                $last = $lastSent[$branch->id] ?? null;

                return $last === null || Carbon::parse($last)->lt($due);
            })->map(fn (Branch $branch): array => [
                'label' => $branch->name,
                'detail' => ($branch->sends_to_hq === 'weekly' ? 'Sends weekly' : 'Sends monthly').' · last sent '.(isset($lastSent[$branch->id]) ? Carbon::parse($lastSent[$branch->id])->format('d M') : 'never'),
                'amount' => null,
                'link' => '/cash/branch-transfers',
            ])->values()->all());
    }

    /** @return array<string, int> key => item count, for the dashboard. */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->alerts() as $alert) {
            $counts[$alert['key']] = count($alert['items']);
        }

        return $counts;
    }

    private function cashNotHandedOver(int $deadlineHours): array
    {
        // Every method: MoMo / bank money is only booked into the company's
        // accounts when the cashier ticks it at a handover.
        $rows = Payment::query()
            ->forBranch(TenantContext::currentBranchId())
            ->whereNotNull('recorded_by')
            ->awaitingHandover()
            ->where('created_at', '<', now()->subHours($deadlineHours))
            ->selectRaw('recorded_by, sum(amount) as total, count(*) as payments, min(created_at) as oldest')
            ->groupBy('recorded_by')
            ->get();

        $names = User::query()->whereIn('id', $rows->pluck('recorded_by'))->pluck('name', 'id');

        return $this->alert('cash_not_handed_over', 'danger', 'Collections not handed over', "Collectors holding money (cash, MoMo or bank) for more than {$deadlineHours} hours. Ask them to hand it over today.",
            $rows->map(fn ($row): array => [
                'label' => $names[$row->recorded_by] ?? 'Unknown collector',
                'detail' => "{$row->payments} payment(s) since ".Carbon::parse($row->oldest)->format('d M H:i'),
                'amount' => number_format((float) $row->total, 2, '.', ''),
                'link' => null,
            ])->all());
    }

    /**
     * A payment a phone recorded (the cash was taken) that the server
     * refused and that has not come in since — the phone keeps retrying, but
     * the office would otherwise never see it (owner, 2026-10-28).
     */
    private function phonePaymentsNotSynced(): array
    {
        $failed = SyncQueue::query()
            ->where('entity_type', 'payment')
            ->where('status', 'failed')
            ->whereNotNull('local_uuid')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('payments')->whereColumn('payments.local_uuid', 'sync_queue.local_uuid'))
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->orderByDesc('id')
            ->get()
            ->unique('local_uuid');

        $customers = Customer::query()->withTrashed()->whereIn('uuid', $failed->map(fn (SyncQueue $row): mixed => $row->payload['customer_uuid'] ?? null)->filter()->unique())->pluck('name', 'uuid');

        return $this->alert('phone_payments_not_synced', 'danger', 'Phone payments not synced', 'A collector recorded these on the phone but the server refused them. Call the collector, fix the cause (e.g. reconnect the customer), and they sync on their own.',
            $failed->map(fn (SyncQueue $row): array => [
                'label' => ($customers[$row->payload['customer_uuid'] ?? ''] ?? 'Unknown customer').' — '.(Payment::METHOD_LABELS[$row->payload['method'] ?? 'cash'] ?? 'Cash'),
                'detail' => (string) $row->error_message,
                'amount' => isset($row->payload['amount']) ? number_format((float) $row->payload['amount'], 2, '.', '') : null,
                'link' => null,
            ])->values()->all());
    }

    /**
     * A second payment for the same customer in the same month, waiting for
     * someone to compare it with the first — and, over time, which collector
     * keeps recording them (PaymentService::flagPossibleDuplicate()).
     */
    private function possibleDuplicatePayments(): array
    {
        // Waiting for a decision — and, for the record, this month's repeats
        // verified on the spot because the payment bore the reason out.
        $payments = Payment::query()
            ->forBranch(TenantContext::currentBranchId())
            ->whereNotNull('duplicate_of_id')
            ->where(fn ($query) => $query->where('verification_status', 'pending')
                ->orWhere(fn ($verified) => $verified->where('verification_status', 'verified')->where('created_at', '>=', Carbon::now(BusinessTimezone::WAT)->startOfMonth()->setTimezone('UTC'))))
            ->with(['customer:id,uuid,name', 'recordedBy:id,name'])
            ->latest('id')->limit(100)
            ->get(['id', 'uuid', 'customer_id', 'amount', 'recorded_by', 'duplicate_reason', 'verification_status', 'created_at']);

        return $this->alert('possible_duplicate_payments', 'warning', 'Possible duplicate payments', 'A second payment for the same customer this month. Open the pending ones, compare each with the first, then approve or reject it. Verified ones were borne out by the payment itself (a real prepayment, or debt owed) and are listed for the record.',
            $payments->map(fn (Payment $payment): array => [
                'label' => ($payment->customer?->name ?? 'Customer').' — '.($payment->recordedBy?->name ?? 'unknown collector'),
                'detail' => (Payment::DUPLICATE_REASONS[$payment->duplicate_reason] ?? 'Possible duplicate')
                    .($payment->verification_status === 'verified' ? ' · verified on the spot' : ' · waiting for the office'),
                'amount' => (string) $payment->amount,
                'link' => "/payments/{$payment->uuid}",
            ])->all());
    }

    /**
     * A customer registered late who says they paid someone for the
     * connection — the "connected, cash pocketed, registered late" signal
     * (owner, 2026-10-26). Registered in the last 60 days.
     */
    private function lateRegistrationsPaidSomeone(): array
    {
        // Until a manager settles each one — however old (owner, 2026-10-28).
        $customers = Customer::query()
            ->where('customer_says_paid_amount', '>', 0)
            ->whereNull('says_paid_settled_at')
            ->latest('id')->limit(100)
            ->get(['id', 'uuid', 'name', 'customer_says_paid_amount', 'customer_says_paid_to', 'connected_by_user_id']);

        $names = User::query()->whereIn('id', $customers->pluck('connected_by_user_id')->filter())->pluck('name', 'id');

        return $this->alert('late_registration_paid_someone', 'danger', 'Customers who say they paid someone', 'Registered late, and the customer says they paid for the connection. Find where that money went, then settle it on the Late registration card of the customer.',
            $customers->map(fn (Customer $customer): array => [
                'label' => $customer->name,
                'detail' => 'Says paid '.($customer->customer_says_paid_to ?? 'someone').' · connected by '.($names[$customer->connected_by_user_id] ?? 'unknown'),
                'amount' => (string) $customer->customer_says_paid_amount,
                'link' => "/customers/{$customer->uuid}",
            ])->all());
    }

    private function handoversNotReceived(int $deadlineHours): array
    {
        return $this->alert('handover_not_received', 'warning', 'Handovers not received', 'Submitted by a collector but no cashier has counted them yet.',
            Remittance::query()->with('collector')->where('status', 'submitted')->where('submitted_at', '<', now()->subHours($deadlineHours))->get()
                ->map(fn (Remittance $remittance): array => [
                    'label' => "{$remittance->number} — {$remittance->collector?->name}",
                    'detail' => 'Submitted '.$remittance->submitted_at?->format('d M H:i'),
                    'amount' => (string) $remittance->declared_cash,
                    'link' => "/cash/remittances/{$remittance->uuid}",
                ])->all());
    }

    private function openShortages(): array
    {
        return $this->alert('open_shortages', 'danger', 'Open shortages', 'Handovers that came in short. The collector repays, or a manager writes it off with a reason.',
            Remittance::query()->with('collector')->where('shortage_status', 'open')->get()
                ->map(fn (Remittance $remittance): array => [
                    'label' => "{$remittance->number} — {$remittance->collector?->name}",
                    'detail' => 'Received '.$remittance->received_at?->format('d M H:i'),
                    'amount' => $remittance->shortageOwed(),
                    'link' => "/cash/remittances/{$remittance->uuid}",
                ])->all());
    }

    /**
     * Cash handed in above what was expected, not yet used to cover a
     * shortage (owner, 2026-10-28) — usually a payment that synced late. It
     * stays visible until a later shortage uses it.
     */
    private function collectorCredits(): array
    {
        return $this->alert('collector_credits', 'warning', 'Extra cash handed in', 'More cash than expected came in on these handovers — usually a payment that synced late. It covers the collector\'s next shortage; find out where it came from.',
            Remittance::query()->with('collector')->where('credit_remaining', '>', 0)->orderBy('received_at')->get()
                ->map(fn (Remittance $remittance): array => [
                    'label' => "{$remittance->number} — {$remittance->collector?->name}",
                    'detail' => 'Received '.$remittance->received_at?->format('d M H:i'),
                    'amount' => (string) $remittance->credit_remaining,
                    'link' => "/cash/remittances/{$remittance->uuid}",
                ])->all());
    }

    private function walletLinesNotFound(): array
    {
        // Until each one is settled — however old (owner, 2026-10-28: nothing drops off silently).
        return $this->alert('wallet_not_found', 'danger', 'MoMo / bank payments not found', 'The cashier could not see these on the wallet or bank statement — the collector owes them. Open the handover: found it, paid in cash, or written off.',
            RemittanceLine::query()
                ->with(['remittance.collector', 'payment.customer'])
                ->whereIn('method', Payment::NON_CASH_METHODS)
                ->whereNull('outcome')
                ->whereHas('remittance', fn ($query) => $query->where('status', 'received'))
                ->get()
                ->map(fn (RemittanceLine $line): array => [
                    'label' => ($line->payment?->customer?->name ?? 'Customer').' — txn '.($line->reference ?? '?'),
                    'detail' => "{$line->remittance?->number} by {$line->remittance?->collector?->name}",
                    'amount' => (string) $line->amount,
                    'link' => $line->remittance ? "/cash/remittances/{$line->remittance->uuid}" : null,
                ])->all());
    }

    private function receiptsScannedBeforeRecording(int $deadlineHours): array
    {
        return $this->alert('scanned_unrecorded', 'danger', 'Receipts a customer holds but nobody recorded', "A customer scanned these receipts, and still no payment is recorded on them {$deadlineHours} hours later. Money may have been taken and kept.",
            ReceiptLeaf::query()->with('block.holder')
                ->where('status', ReceiptLeaf::STATUS_UNUSED)
                ->whereNotNull('scanned_unused_at')
                ->where('scanned_unused_at', '<', now()->subHours($deadlineHours))
                ->get()
                ->map(fn (ReceiptLeaf $leaf): array => [
                    'label' => "{$leaf->number} — {$leaf->block?->holder?->name}",
                    'detail' => 'Scanned '.$leaf->scanned_unused_at?->format('d M H:i'),
                    'amount' => null,
                    'link' => '/receipt-stock?search='.urlencode($leaf->number),
                ])->all());
    }

    private function unaccountedReceiptNumbers(): array
    {
        $rows = ReceiptLeaf::query()
            ->where('status', ReceiptLeaf::STATUS_UNUSED)
            ->whereHas('block', fn ($query) => $query->where('status', 'closed'))
            ->with('block.holder')
            ->get()
            ->groupBy('receipt_block_id');

        return $this->alert('unaccounted_numbers', 'warning', 'Receipt numbers not accounted for', 'Unused numbers in blocks that were closed — each must be found and cancelled, or explained.',
            $rows->map(fn ($leaves): array => [
                'label' => "{$leaves->first()->block?->number} — {$leaves->first()->block?->holder?->name}",
                'detail' => $leaves->count().' number(s), e.g. '.$leaves->first()->number,
                'amount' => null,
                'link' => '/receipt-stock?status=closed',
            ])->values()->all());
    }

    private function tillsNotClosed(): array
    {
        $yesterday = BusinessTimezone::now()->subDay()->toDateString();

        $tillIds = CashMovement::query()
            ->whereDate('business_date', $yesterday)
            ->whereHas('account', fn ($query) => $query->where('kind', CashAccount::KIND_TILL))
            ->distinct()
            ->pluck('cash_account_id');

        $closed = CashDayClose::query()->whereDate('business_date', $yesterday)->whereNotNull('closed_at')->pluck('cash_account_id');

        return $this->alert('till_not_closed', 'warning', 'Tills not closed yesterday', 'Every till that took or paid money must be counted at the end of the day.',
            CashAccount::query()->with('holder')->whereIn('id', $tillIds->diff($closed))->get()
                ->map(fn (CashAccount $till): array => [
                    'label' => $till->name,
                    'detail' => "Used on {$yesterday}, not closed",
                    'amount' => null,
                    'link' => "/cash/accounts/{$till->uuid}",
                ])->all());
    }

    private function closeDifferences(): array
    {
        return $this->alert('close_differences', 'warning', 'Till count differences (last 7 days)', 'The drawer did not match the book at day close.',
            CashDayClose::query()->with('account')
                ->where('variance', '!=', 0)
                ->whereDate('business_date', '>=', BusinessTimezone::now()->subDays(7)->toDateString())
                ->get()
                ->map(fn (CashDayClose $close): array => [
                    'label' => (string) $close->account?->name,
                    'detail' => $close->business_date?->toDateString().($close->note ? " — {$close->note}" : ''),
                    'amount' => (string) $close->variance,
                    'link' => $close->account ? "/cash/accounts/{$close->account->uuid}" : null,
                ])->all());
    }

    private function advancesNotJustified(): array
    {
        return $this->alert('advances_overdue', 'danger', 'Cash advances not justified', 'Advances past their deadline without receipts or returned change.',
            Voucher::query()->with('requestedBy')
                ->where('kind', Voucher::KIND_ADVANCE)
                ->where('status', 'paid')
                ->whereDate('settle_due_on', '<', BusinessTimezone::today())
                ->get()
                ->map(fn (Voucher $voucher): array => $this->voucherItem($voucher, 'Due '.$voucher->settle_due_on?->toDateString()))
                ->all());
    }

    private function approvedVouchersUnpaid(): array
    {
        return $this->alert('vouchers_unpaid', 'warning', 'Approved vouchers not paid', 'Approved more than '.self::UNPAID_VOUCHER_DAYS.' days ago and still not paid — pay or cancel them.',
            Voucher::query()->with('requestedBy')
                ->where('status', 'approved')
                ->where('approved_at', '<', now()->subDays(self::UNPAID_VOUCHER_DAYS))
                ->get()
                ->map(fn (Voucher $voucher): array => $this->voucherItem($voucher, 'Approved '.$voucher->approved_at?->format('d M')))
                ->all());
    }

    private function vouchersWithoutSignedCopy(): array
    {
        return $this->alert('vouchers_unsigned', 'warning', 'Paid vouchers without a signed copy', 'Print the voucher, have the payee sign it, and upload the photo.',
            Voucher::query()->with('requestedBy')
                ->whereIn('status', ['paid', 'settled'])
                ->whereNull('signed_copy_path')
                ->where('paid_at', '<', now()->subDays(self::MISSING_SIGNATURE_DAYS))
                ->get()
                ->map(fn (Voucher $voucher): array => $this->voucherItem($voucher, "Paid to {$voucher->received_by_name}"))
                ->all());
    }

    /**
     * The month's expenses are above its verified income (subscription +
     * installation; technician fees are never income) — docs/plans/
     * expenditures-and-spending-controls.md item 6. A warning, never a block.
     */
    private function monthInDeficit(): array
    {
        $summary = $this->spending->summary(BusinessTimezone::currentPeriod());

        return $this->alert('month_deficit', 'danger', 'Spending above income this month', 'This month\'s expenses are higher than the verified income so far. Hold back non-essential bons.',
            $summary['deficit'] ? [[
                'label' => BusinessTimezone::periodStart($summary['period'])->format('F Y'),
                'detail' => 'Spent '.number_format((float) $summary['spent'], 0, '.', ' ').' FCFA against '.number_format((float) $summary['income'], 0, '.', ' ').' FCFA income',
                'amount' => ltrim($summary['net'], '-'),
                'link' => '/resources/expenditures',
            ]] : []);
    }

    /**
     * @return array{label: string, detail: string, amount: string, link: string}
     */
    private function voucherItem(Voucher $voucher, string $detail): array
    {
        return [
            'label' => "{$voucher->number} — {$voucher->payee_name}",
            'detail' => $detail.' · requested by '.($voucher->requestedBy?->name ?? '—'),
            'amount' => (string) $voucher->amount,
            'link' => "/vouchers/{$voucher->uuid}",
        ];
    }

    /**
     * @param  list<array{label: string, detail: string, amount: ?string, link: ?string}>  $items
     * @return array{key: string, severity: string, title: string, advice: string, items: list<array{label: string, detail: string, amount: ?string, link: ?string}>}
     */
    private function alert(string $key, string $severity, string $title, string $advice, array $items): array
    {
        return ['key' => $key, 'severity' => $severity, 'title' => $title, 'advice' => $advice, 'items' => array_values($items)];
    }
}
