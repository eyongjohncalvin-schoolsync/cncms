<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\Payment;
use App\Models\User;
use App\Services\AreaSummaryService;
use App\Services\ManuscriptService;
use App\Services\MoneyBalanceService;
use App\Services\ZonePerformanceService;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Today on the phone (docs/plans/mobile-manager-app.md, wave 5) — read
 * only, for whoever sees the whole cash book (cash.view, the same audience
 * as the dashboard's zone card and the Money balance page): today's
 * collections by method and by collector, the month so far, the zones,
 * and the Money balance checks. Every figure comes from the services the
 * web pages use; the branch is the person's own (TenantContext).
 */
class TodayController extends Controller
{
    public function index(Request $request, ManuscriptService $manuscripts, ZonePerformanceService $zones, MoneyBalanceService $balance): JsonResponse
    {
        $this->authorize('viewAny', CashAccount::class);

        $today = BusinessTimezone::today();
        $period = BusinessTimezone::currentPeriod();
        $month = $manuscripts->summary(['period' => $period]);
        $money = $balance->forMonth($period);

        return response()->json(['data' => [
            'today' => $today,
            'collections' => $this->collectionsOn($today),
            'month' => [
                'period' => $period,
                'collected' => (string) $month['total_collected'],
                'collection_rate' => $month['collection_rate'],
                'arrears' => (string) $month['total_arrears'],
                // The same area figures as Home and the web dashboard (AreaSummaryService).
                'area' => app(AreaSummaryService::class)->forViewer($period),
            ],
            'zones' => $zones->summary(),
            'balance' => [
                'recorded' => $money['collections']['totals']['recorded'],
                'with_collectors' => bcadd($money['collections']['totals']['with_collector'], $money['collections']['totals']['waiting'], 2),
                'checks' => $money['checks'],
            ],
        ]]);
    }

    /**
     * Payments recorded today in the person's branch — reversed or rejected
     * ones left out — by method and by who took them.
     *
     * @return array{total: string, count: int, by_method: list<array{method: string, label: string, total: string, count: int}>, by_collector: list<array{name: string, total: string, count: int}>}
     */
    private function collectionsOn(string $date): array
    {
        [$from, $to] = BusinessTimezone::dayBoundsUtc($date);

        $payments = Payment::query()
            ->forBranch(TenantContext::currentBranchId())
            ->income()
            ->whereNull('reversed_at')
            ->where(fn ($query) => $query->whereNull('verification_status')->orWhere('verification_status', '!=', 'rejected'))
            ->whereBetween('created_at', [$from, $to])
            ->get(['amount', 'method', 'recorded_by']);

        $names = User::query()->whereIn('id', $payments->pluck('recorded_by')->filter()->unique())->pluck('name', 'id');
        $sum = fn ($group): string => number_format((float) $group->sum('amount'), 2, '.', '');

        return [
            'total' => $sum($payments),
            'count' => $payments->count(),
            'by_method' => $payments->groupBy(fn (Payment $payment): string => $payment->method ?? 'cash')
                ->map(fn ($group, string $method): array => ['method' => $method, 'label' => Payment::METHOD_LABELS[$method] ?? $method, 'total' => $sum($group), 'count' => $group->count()])
                ->sortByDesc('total')->values()->all(),
            'by_collector' => $payments->groupBy('recorded_by')
                ->map(fn ($group, $userId): array => ['name' => (string) ($names[$userId] ?? 'Office'), 'total' => $sum($group), 'count' => $group->count()])
                ->sortByDesc(fn (array $row): float => (float) $row['total'])->values()->all(),
        ];
    }
}
