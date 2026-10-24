<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashDayClose;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Payment;
use App\Models\ReceiptLeaf;
use App\Models\Remittance;
use App\Models\RemittanceLine;
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
            $this->handoversNotReceived($deadlineHours),
            $this->openShortages(),
            $this->walletLinesNotFound(),
            $this->receiptsScannedBeforeRecording($deadlineHours),
            $this->unaccountedReceiptNumbers(),
            $this->tillsNotClosed(),
            $this->closeDifferences(),
            $this->advancesNotJustified(),
            $this->approvedVouchersUnpaid(),
            $this->vouchersWithoutSignedCopy(),
            $this->monthInDeficit(),
        ], fn (?array $alert): bool => $alert !== null && $alert['items'] !== []));
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
        $start = Company::cached()?->cash_controls_start_date;

        $rows = Payment::query()
            ->forBranch(TenantContext::currentBranchId())
            ->where('method', 'cash')
            ->whereNotNull('recorded_by')
            ->where('verification_status', '!=', 'rejected')
            ->whereDoesntHave('remittanceLine')
            ->when($start !== null, fn ($query) => $query->whereDate('created_at', '>=', $start))
            ->where('created_at', '<', now()->subHours($deadlineHours))
            ->selectRaw('recorded_by, sum(amount) as total, count(*) as payments, min(created_at) as oldest')
            ->groupBy('recorded_by')
            ->get();

        $names = User::query()->whereIn('id', $rows->pluck('recorded_by'))->pluck('name', 'id');

        return $this->alert('cash_not_handed_over', 'danger', 'Cash not handed over', "Collectors holding cash for more than {$deadlineHours} hours. Ask them to hand it over today.",
            $rows->map(fn ($row): array => [
                'label' => $names[$row->recorded_by] ?? 'Unknown collector',
                'detail' => "{$row->payments} payment(s) since ".Carbon::parse($row->oldest)->format('d M H:i'),
                'amount' => number_format((float) $row->total, 2, '.', ''),
                'link' => null,
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
                    'amount' => ltrim((string) $remittance->cash_variance, '-'),
                    'link' => "/cash/remittances/{$remittance->uuid}",
                ])->all());
    }

    private function walletLinesNotFound(): array
    {
        return $this->alert('wallet_not_found', 'danger', 'Mobile money not found in the wallet', 'The cashier could not see these MoMo / Orange Money transactions in the company wallet. Check the statement and the customer.',
            RemittanceLine::query()
                ->with(['remittance.collector', 'payment.customer'])
                ->whereIn('method', array_keys(CashAccount::WALLET_PROVIDERS))
                ->where('wallet_confirmed', false)
                ->whereHas('remittance', fn ($query) => $query->where('status', 'received')->where('received_at', '>=', now()->subDays(self::LOOKBACK_DAYS)))
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
