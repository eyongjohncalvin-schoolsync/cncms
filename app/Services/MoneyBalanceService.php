<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BranchTransfer;
use App\Models\CashAccount;
use App\Models\CashDayClose;
use App\Models\CashMovement;
use App\Models\CashShortage;
use App\Models\CashStatementCount;
use App\Models\CashTransfer;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\TechnicianFeePayout;
use App\Models\Voucher;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Money balance page (owner, 2026-10-28, wave 5): one branch's month on
 * one screen — where every franc collected went, what each account started
 * and ended with, what went out and what is missing — and the checks that
 * prove the chain balances. Read only; every figure comes from the ledgers
 * (payments, handovers, cash movements, bons, shortages), never stored.
 *
 * Branch: the fenced user's branch or the switcher's choice
 * (TenantContext::currentBranchId()). Customer history (payments, bills) is
 * not fenced by BranchScope, so it is narrowed here explicitly.
 */
class MoneyBalanceService
{
    /** Movement kinds that only move money between the company's own accounts. */
    public const array INTERNAL_KINDS = [
        CashMovement::KIND_TRANSFER,
        CashMovement::KIND_DEPOSIT,
        CashMovement::KIND_BRANCH_TRANSFER,
        CashMovement::KIND_BRANCH_TRANSFER_RETURN,
    ];

    public const array KIND_LABELS = [
        CashMovement::KIND_REMITTANCE_CASH => 'Handovers from collectors',
        CashMovement::KIND_DESK_PAYMENT => 'Desk payments',
        CashMovement::KIND_REMITTANCE_WALLET => 'Mobile money and bank payments',
        CashMovement::KIND_DEPOSIT => 'Bank deposits',
        CashMovement::KIND_TRANSFER => 'Moves between accounts',
        CashMovement::KIND_VOUCHER_PAYOUT => 'Bons paid',
        CashMovement::KIND_ADVANCE_RETURN => 'Advance change returned',
        CashMovement::KIND_SHORTAGE_REPAYMENT => 'Shortages repaid',
        CashMovement::KIND_CLOSE_ADJUSTMENT => 'Day-close differences',
        CashMovement::KIND_OPENING_BALANCE => 'Opening balances',
        CashMovement::KIND_TECHNICIAN_FEE_PAYOUT => 'Technician fees paid',
        CashMovement::KIND_BON_CORRECTION => 'Bon corrections',
        CashMovement::KIND_BRANCH_TRANSFER => 'Between branches',
        CashMovement::KIND_BRANCH_TRANSFER_RETURN => 'Branch transfers returned',
    ];

    public const array STAGE_LABELS = [
        'handed_in' => 'Handed in',
        'not_confirmed' => 'Handed in, not found on the statement',
        'written_off' => 'Written off',
        'waiting' => 'In a handover not yet received',
        'with_collector' => 'Still with the collector',
        'before_controls' => 'Before cash controls',
    ];

    public function __construct(
        private readonly CashBookService $cashBook,
        private readonly ZonePerformanceService $zonePerformance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forMonth(string $period): array
    {
        $branchId = TenantContext::currentBranchId();
        $collections = $this->collections($period, $branchId);
        $handovers = $this->handovers($period);
        $accounts = $this->accounts($period);
        $moneyOut = $this->moneyOut($period);
        $shortages = $this->shortages($period);
        $zones = $this->zonePerformance->forPeriod($period);

        return [
            'period' => $period,
            'is_current' => $period === BusinessTimezone::currentPeriod(),
            'collections' => $collections,
            'handovers' => $handovers,
            'accounts' => $accounts['rows'],
            'movements_by_kind' => $accounts['by_kind'],
            'money_out' => $moneyOut,
            'shortages' => $shortages,
            'in_transit' => $this->inTransit(),
            'zones' => $zones,
            'checks' => $this->checks($period, $collections, $handovers, $accounts, $moneyOut, $shortages),
        ];
    }

    /**
     * Every payment recorded in the month, by method, split by where its
     * money is now. The stages add up to what was recorded — nothing else.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    private function collections(string $period, ?int $branchId): array
    {
        [$from, $to] = BusinessTimezone::periodBoundsUtc($period);
        $start = Company::cached()?->cash_controls_start_date;
        $controlsFrom = $start !== null ? BusinessTimezone::dayBoundsUtc($start->toDateString())[0] : null;

        // A formatted date, never user input — safe inline (a bound parameter would break the GROUP BY).
        $beforeControls = $controlsFrom !== null ? "when payments.created_at < '{$controlsFrom->format('Y-m-d H:i:sP')}' then 'before_controls'" : '';
        $stage = "case {$beforeControls}
            when remittances.status = 'received' and remittance_lines.outcome = 'written_off' then 'written_off'
            when remittances.status = 'received' and remittance_lines.method <> 'cash' and remittance_lines.outcome is null then 'not_confirmed'
            when remittances.status = 'received' then 'handed_in'
            when remittances.status = 'submitted' then 'waiting'
            else 'with_collector' end";

        $rows = Payment::query()
            ->leftJoin('remittance_lines', 'remittance_lines.payment_id', '=', 'payments.id')
            ->leftJoin('remittances', 'remittances.id', '=', 'remittance_lines.remittance_id')
            ->whereBetween('payments.created_at', [$from, $to])
            // A correction's money is the original's; a rejected payment where no money changed hands is no money.
            ->whereNull('payments.paid_with_payment_id')
            ->where(fn (Builder $query) => $query->where('payments.verification_status', '!=', 'rejected')->orWhere('payments.money_taken', true))
            ->when($branchId !== null, fn (Builder $query) => $query->where('payments.branch_id', $branchId))
            ->selectRaw("coalesce(payments.method, 'cash') as method, {$stage} as stage, sum(payments.amount) as total, count(*) as payments_count")
            ->groupByRaw('1, 2')
            ->get();

        $stages = array_keys(self::STAGE_LABELS);
        $totals = array_fill_keys([...$stages, 'recorded'], '0.00');
        $byMethod = [];

        foreach (Payment::METHODS as $method) {
            $byMethod[$method] = ['method' => $method, 'label' => Payment::METHOD_LABELS[$method] ?? $method, 'recorded' => '0.00', 'count' => 0] + array_fill_keys($stages, '0.00');
        }

        foreach ($rows as $row) {
            $method = $row->method;
            $byMethod[$method] ??= ['method' => $method, 'label' => $method, 'recorded' => '0.00', 'count' => 0] + array_fill_keys($stages, '0.00');
            $amount = $this->money($row->total);
            $byMethod[$method][$row->stage] = bcadd($byMethod[$method][$row->stage], $amount, 2);
            $byMethod[$method]['recorded'] = bcadd($byMethod[$method]['recorded'], $amount, 2);
            $byMethod[$method]['count'] += (int) $row->payments_count;
            $totals[$row->stage] = bcadd($totals[$row->stage], $amount, 2);
            $totals['recorded'] = bcadd($totals['recorded'], $amount, 2);
        }

        return [
            'rows' => array_values(array_filter($byMethod, fn (array $row): bool => $row['count'] > 0)),
            'totals' => $totals,
        ];
    }

    /**
     * Handovers received in the month: what the collectors owed, what was
     * counted into the tills, and the gaps.
     *
     * @return array<string, mixed>
     */
    private function handovers(string $period): array
    {
        [$from, $to] = BusinessTimezone::periodBoundsUtc($period);
        [$firstDay, $lastDay] = BusinessTimezone::periodDates($period);

        $received = Remittance::query()->where('status', 'received')->whereBetween('received_at', [$from, $to])
            ->selectRaw('count(*) as handovers_count, coalesce(sum(expected_cash), 0) as expected, coalesce(sum(received_cash), 0) as received,
                coalesce(sum(bons_total), 0) as bons, coalesce(sum(case when cash_variance < 0 then -cash_variance else 0 end), 0) as short,
                coalesce(sum(case when cash_variance > 0 then cash_variance else 0 end), 0) as over,
                coalesce(sum(case when at_desk then 1 else 0 end), 0) as at_desk_count')
            ->first();

        $shortOpen = Remittance::query()->where('status', 'received')->whereBetween('received_at', [$from, $to])->where('shortage_status', 'open')->get()
            ->reduce(fn (string $sum, Remittance $remittance): string => bcadd($sum, $remittance->shortageOwed(), 2), '0.00');

        // The same cash seen from the tills: what was booked in from these handovers.
        $intoTills = CashMovement::query()
            ->whereBetween('business_date', [$firstDay, $lastDay])
            ->where('source_type', Remittance::class)
            ->whereIn('kind', [CashMovement::KIND_REMITTANCE_CASH, CashMovement::KIND_DESK_PAYMENT])
            ->selectRaw("coalesce(sum(case when direction = 'in' then amount else -amount end), 0) as total")
            ->value('total');

        return [
            'count' => (int) $received->handovers_count,
            'at_desk_count' => (int) $received->at_desk_count,
            'expected' => $this->money($received->expected),
            'received' => $this->money($received->received),
            'bons' => $this->money($received->bons),
            'short' => $this->money($received->short),
            'over' => $this->money($received->over),
            'short_open' => $shortOpen,
            'into_tills' => $this->money($intoTills),
        ];
    }

    /**
     * Each account's month: the balance it started with, what came in and
     * went out, the balance it ended with, and how it was counted.
     *
     * @return array{rows: list<array<string, mixed>>, by_kind: list<array<string, mixed>>, unclosed_days: int, uncounted: int, count_differences: int}
     */
    private function accounts(string $period): array
    {
        [$firstDay, $lastDay] = BusinessTimezone::periodDates($period);
        $today = $this->cashBook->today();
        $monthEnded = $lastDay < $today;

        $totals = CashMovement::query()
            ->where('business_date', '<=', $lastDay)
            ->selectRaw("cash_account_id,
                coalesce(sum(case when business_date < ? then (case when direction = 'in' then amount else -amount end) else 0 end), 0) as opening,
                coalesce(sum(case when business_date >= ? and direction = 'in' then amount else 0 end), 0) as money_in,
                coalesce(sum(case when business_date >= ? and direction = 'out' then amount else 0 end), 0) as money_out", [$firstDay, $firstDay, $firstDay])
            ->groupBy('cash_account_id')
            ->get()->keyBy('cash_account_id');

        $accounts = CashAccount::query()->with('holder')
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereIn('id', $totals->keys()))
            ->where('created_at', '<=', BusinessTimezone::periodBoundsUtc($period)[1])
            ->orderByRaw("case kind when 'till' then 0 when 'safe' then 1 when 'bank' then 2 else 3 end")->orderBy('name')
            ->get();

        // Till days that moved money and were never closed (today is still open).
        $daysUsed = CashMovement::query()
            ->whereIn('cash_account_id', $accounts->where('kind', CashAccount::KIND_TILL)->pluck('id'))
            ->whereBetween('business_date', [$firstDay, min($lastDay, Carbon::parse($today)->subDay()->toDateString())])
            ->selectRaw('cash_account_id, business_date')->distinct()->get()
            ->groupBy('cash_account_id')->map(fn (Collection $days): Collection => $days->map(fn ($day): string => Carbon::parse($day->business_date)->toDateString()));

        $closes = CashDayClose::query()->whereNotNull('closed_at')->whereBetween('business_date', [$firstDay, $lastDay])
            ->orderBy('business_date')->get()->groupBy('cash_account_id');

        $statements = CashStatementCount::query()->where('period', $period)->get()->keyBy('cash_account_id');

        $unclosedDays = 0;
        $uncounted = 0;
        $countDifferences = 0;

        $rows = $accounts->map(function (CashAccount $account) use ($totals, $daysUsed, $closes, $statements, $monthEnded, &$unclosedDays, &$uncounted, &$countDifferences): array {
            $row = $totals->get($account->id);
            $opening = $this->money($row?->opening);
            $in = $this->money($row?->money_in);
            $out = $this->money($row?->money_out);
            $accountCloses = $closes->get($account->id, collect());
            $lastClose = $accountCloses->last();
            $statement = $statements->get($account->id);

            $closedDates = $accountCloses->map(fn (CashDayClose $close): string => $close->business_date->toDateString());
            $notClosed = $account->isTill() ? $daysUsed->get($account->id, collect())->diff($closedDates)->values() : collect();
            $unclosedDays += $notClosed->count();

            $differences = $accountCloses->filter(fn (CashDayClose $close): bool => bccomp((string) $close->variance, '0', 2) !== 0);
            $countDifferences += $differences->count();

            $needsStatement = ! $account->isTill() && $monthEnded && $account->is_active;

            if ($needsStatement && $statement === null) {
                $uncounted++;
            }

            if ($statement !== null && bccomp((string) $statement->difference, '0', 2) !== 0) {
                $countDifferences++;
            }

            return [
                'uuid' => $account->uuid,
                'name' => $account->name,
                'kind' => $account->kind,
                'holder_name' => $account->holder?->name,
                'is_active' => $account->is_active,
                'opening' => $opening,
                'in' => $in,
                'out' => $out,
                'closing' => bcsub(bcadd($opening, $in, 2), $out, 2),
                'days_closed' => $accountCloses->count(),
                'days_not_closed' => $notClosed->all(),
                'close_variance' => $differences->reduce(fn (string $sum, CashDayClose $close): string => bcadd($sum, (string) $close->variance, 2), '0.00'),
                'last_close' => $lastClose ? [
                    'date' => $lastClose->business_date->toDateString(),
                    'counted' => (string) $lastClose->counted,
                    'variance' => (string) $lastClose->variance,
                ] : null,
                'statement' => $statement ? [
                    'book' => (string) $statement->book_balance,
                    'statement' => (string) $statement->statement_balance,
                    'difference' => (string) $statement->difference,
                ] : null,
                'needs_statement' => $needsStatement,
            ];
        })
            // A closed account with nothing in it this month has nothing to show.
            ->filter(fn (array $row): bool => $row['is_active'] || bccomp($row['opening'], '0', 2) !== 0 || bccomp($row['in'], '0', 2) !== 0 || bccomp($row['out'], '0', 2) !== 0)
            ->values()->all();

        $byKind = CashMovement::query()->whereBetween('business_date', [$firstDay, $lastDay])
            ->selectRaw("kind, coalesce(sum(case when direction = 'in' then amount else 0 end), 0) as money_in, coalesce(sum(case when direction = 'out' then amount else 0 end), 0) as money_out")
            ->groupBy('kind')->get()
            ->map(fn ($row): array => [
                'kind' => $row->kind,
                'label' => self::KIND_LABELS[$row->kind] ?? $row->kind,
                'internal' => in_array($row->kind, self::INTERNAL_KINDS, true),
                'in' => $this->money($row->money_in),
                'out' => $this->money($row->money_out),
            ])
            ->sortBy(fn (array $row): string => ($row['internal'] ? '1' : '0').$row['label'])->values()->all();

        return ['rows' => $rows, 'by_kind' => $byKind, 'unclosed_days' => $unclosedDays, 'uncounted' => $uncounted, 'count_differences' => $countDifferences];
    }

    /**
     * Money that left the company in the month: bons by what they paid for,
     * and technician fees passed on.
     *
     * @return array<string, mixed>
     */
    private function moneyOut(string $period): array
    {
        [$from, $to] = BusinessTimezone::periodBoundsUtc($period);

        $bons = Voucher::query()->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
            ->selectRaw('coalesce(origin, kind) as purpose, count(*) as bons_count, sum(amount) as total')
            ->groupByRaw('coalesce(origin, kind)')->get()
            ->map(fn ($row): array => [
                'purpose' => $row->purpose,
                'label' => match ($row->purpose) {
                    Voucher::ORIGIN_REFUND => 'Refunds to customers',
                    Voucher::ORIGIN_RETURN_TO_COLLECTOR => 'Returned to collectors',
                    Voucher::ORIGIN_SALARY => 'Salaries',
                    Voucher::KIND_ADVANCE => 'Cash advances',
                    default => 'Expenses',
                },
                'count' => (int) $row->bons_count,
                'total' => $this->money($row->total),
            ])->sortBy('label')->values()->all();

        $advancesOpen = Voucher::query()->where('kind', Voucher::KIND_ADVANCE)->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])->get(['id', 'number', 'amount', 'settle_due_on']);

        $technicianFees = TechnicianFeePayout::query()->whereBetween('paid_at', [$from, $to])->sum('amount');

        return [
            'bons' => $bons,
            'bons_total' => array_reduce($bons, fn (string $sum, array $row): string => bcadd($sum, $row['total'], 2), '0.00'),
            'advances_unsettled' => $this->money($advancesOpen->sum('amount')),
            'advances_overdue' => $advancesOpen->filter(fn (Voucher $voucher): bool => $voucher->settle_due_on !== null && $voucher->settle_due_on->toDateString() < $this->cashBook->today())->count(),
            'technician_fees' => $this->money($technicianFees),
            'refunds_unpaid' => $this->refundsUnpaid($period),
        ];
    }

    /**
     * Payments reversed in the month whose money goes back on a bon not paid yet.
     *
     * @return list<array{payment_uuid: string, customer_name: ?string, amount: string, bon_number: ?string, bon_uuid: ?string, bon_status: ?string}>
     */
    private function refundsUnpaid(string $period): array
    {
        [$from, $to] = BusinessTimezone::periodBoundsUtc($period);
        $branchId = TenantContext::currentBranchId();

        return Payment::query()->with(['customer' => fn ($query) => $query->withTrashed(), 'refundVoucher'])
            ->whereBetween('reversed_at', [$from, $to])->whereNotNull('refund_voucher_id')
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->whereHas('refundVoucher', fn (Builder $query) => $query->withoutGlobalScopes()->whereNull('paid_at'))
            ->get()
            ->map(fn (Payment $payment): array => [
                'payment_uuid' => $payment->uuid,
                'customer_name' => $payment->customer?->name,
                'amount' => (string) $payment->amount,
                'bon_number' => $payment->refundVoucher?->number,
                'bon_uuid' => $payment->refundVoucher?->uuid,
                'bon_status' => $payment->refundVoucher?->status,
            ])->values()->all();
    }

    /**
     * Money found missing in the month, and whether it came back.
     *
     * @return array<string, mixed>
     */
    private function shortages(string $period): array
    {
        [$from, $to] = BusinessTimezone::periodBoundsUtc($period);

        $owned = CashShortage::query()->whereBetween('created_at', [$from, $to])
            ->selectRaw('kind, status, count(*) as shortages_count, sum(amount) as total')
            ->groupBy('kind', 'status')->get();

        $rows = $owned->groupBy('kind')->map(function (Collection $group, string $kind): array {
            $row = ['kind' => $kind, 'label' => CashShortage::KIND_LABELS[$kind] ?? $kind, 'count' => (int) $group->sum('shortages_count')];

            foreach ([CashShortage::STATUS_OPEN, CashShortage::STATUS_REPAID, CashShortage::STATUS_WRITTEN_OFF] as $status) {
                $row[$status] = $this->money($group->firstWhere('status', $status)?->total);
            }

            return $row;
        })->values();

        // Collectors' handovers that came in short (wave 1's remittance shortages).
        $collectors = Remittance::query()->where('status', 'received')->whereBetween('received_at', [$from, $to])->where('cash_variance', '<', 0)->get();
        $collectorRow = ['kind' => 'collector_handover', 'label' => 'Collector handed in short', 'count' => $collectors->count(), 'open' => '0.00', 'repaid' => '0.00', 'written_off' => '0.00'];

        foreach ($collectors as $remittance) {
            $owed = $remittance->shortageOwed();
            $covered = bcsub(ltrim((string) $remittance->cash_variance, '-'), $owed, 2);
            $collectorRow['repaid'] = bcadd($collectorRow['repaid'], $covered, 2);
            $status = $remittance->shortage_status === 'written_off' ? 'written_off' : ($remittance->shortage_status === 'open' ? 'open' : 'repaid');
            $collectorRow[$status] = bcadd($collectorRow[$status], $owed, 2);
        }

        if ($collectors->isNotEmpty()) {
            $rows->push($collectorRow);
        }

        $sum = fn (string $status): string => $rows->reduce(fn (string $total, array $row): string => bcadd($total, $row[$status], 2), '0.00');

        return [
            'rows' => $rows->sortBy('label')->values()->all(),
            'open' => $sum('open'),
            'repaid' => $sum('repaid'),
            'written_off' => $sum('written_off'),
        ];
    }

    /**
     * Money on its way between people, the bank or branches right now.
     *
     * @return array{handovers: string, deposits: string, branches: string, count: int}
     */
    private function inTransit(): array
    {
        $transfers = CashTransfer::query()->inTransit()->selectRaw('kind, count(*) as transfers_count, sum(amount) as total')->groupBy('kind')->get()->keyBy('kind');
        $branchId = TenantContext::currentBranchId();
        $branches = BranchTransfer::query()->where('status', BranchTransfer::STATUS_SENT)
            ->when($branchId !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId)))
            ->selectRaw('count(*) as transfers_count, coalesce(sum(amount), 0) as total')->first();

        return [
            'handovers' => $this->money($transfers->get(CashTransfer::KIND_HANDOVER)?->total),
            'deposits' => $this->money($transfers->get(CashTransfer::KIND_DEPOSIT)?->total),
            'branches' => $this->money($branches?->total),
            'count' => (int) $transfers->sum('transfers_count') + (int) $branches?->transfers_count,
        ];
    }

    /**
     * The proof, one line per rule: ok, or what is out of place.
     *
     * @return list<array{key: string, status: string, title: string, detail: string}>
     */
    private function checks(string $period, array $collections, array $handovers, array $accounts, array $moneyOut, array $shortages): array
    {
        $isCurrent = $period === BusinessTimezone::currentPeriod();
        $fmt = fn (string $amount): string => number_format((float) $amount, 0, '.', ' ').' FCFA';
        $notIn = bcadd($collections['totals']['with_collector'], $collections['totals']['waiting'], 2);
        $missing = bcadd($collections['totals']['not_confirmed'], $collections['totals']['written_off'], 2);
        $tillGap = bcsub($handovers['received'], $handovers['into_tills'], 2);
        // Judged only once enough of the month is in (ZonePerformanceService::JUDGE_FROM_DAY).
        $dropped = collect($this->zonePerformance->dropped($period))->pluck('name');
        $refunds = count($moneyOut['refunds_unpaid']);

        $check = fn (string $key, bool $ok, string $title, string $detail, bool $danger = false): array => [
            'key' => $key,
            'status' => $ok ? 'ok' : ($danger ? 'danger' : 'warning'),
            'title' => $title,
            'detail' => $detail,
        ];

        return [
            $check('collections_handed_in', bccomp($notIn, '0', 2) === 0, 'Every payment recorded is handed in',
                bccomp($notIn, '0', 2) === 0 ? 'All '.$fmt($collections['totals']['recorded']).' recorded reached an account or is explained.'
                    : $fmt($notIn).' is still with collectors or in a handover not yet received.', ! $isCurrent),
            $check('collections_found', bccomp($missing, '0', 2) === 0, 'Every mobile money and bank payment was found',
                bccomp($missing, '0', 2) === 0 ? 'Each one was ticked off the statement.' : $fmt($missing).' was not found on a statement or was written off.', true),
            $check('handovers_into_tills', bccomp($tillGap, '0', 2) === 0, 'Handovers match the tills',
                bccomp($tillGap, '0', 2) === 0 ? $fmt($handovers['received']).' counted at handovers, the same booked into the tills.'
                    : 'Handovers counted '.$fmt($handovers['received']).' but the tills booked '.$fmt($handovers['into_tills']).'.', true),
            $check('tills_closed', $accounts['unclosed_days'] === 0, 'Every till day was closed',
                $accounts['unclosed_days'] === 0 ? 'Each day a till moved money, it was counted.' : "{$accounts['unclosed_days']} till day(s) moved money and were never counted."),
            $check('counts_match', $accounts['count_differences'] === 0, 'Counts matched the book',
                $accounts['count_differences'] === 0 ? 'Every day close and statement matched.' : "{$accounts['count_differences']} count(s) differed from the book — see the accounts below.", true),
            $check('statements_counted', $accounts['uncounted'] === 0, 'Every bank, wallet and safe counted for the month',
                $isCurrent ? 'Due after the month ends.' : ($accounts['uncounted'] === 0 ? 'Each statement was entered.' : "{$accounts['uncounted']} account(s) have no statement for the month.")),
            $check('shortages_settled', bccomp($shortages['open'], '0', 2) === 0, 'Every shortage is settled',
                bccomp($shortages['open'], '0', 2) === 0 ? 'Nothing missing is still owed.' : $fmt($shortages['open']).' found missing this month is still owed.', true),
            $check('refunds_paid', $refunds === 0, 'Every reversed payment\'s money went back',
                $refunds === 0 ? 'Each refund or return bon was paid.' : "{$refunds} refund or return bon(s) still unpaid."),
            $check('advances_settled', $moneyOut['advances_overdue'] === 0, 'Every cash advance is accounted for',
                $moneyOut['advances_overdue'] === 0 ? 'No advance is past its settle date.' : "{$moneyOut['advances_overdue']} advance(s) past their settle date without receipts."),
            $check('zones_steady', $dropped->isEmpty(), 'No zone suddenly collects less',
                $dropped->isEmpty() ? 'Each zone collects near its usual rate.' : 'Collecting well below usual: '.$dropped->implode(', ').'.', ! $isCurrent),
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
