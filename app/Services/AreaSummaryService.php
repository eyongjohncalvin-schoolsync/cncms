<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Repositories\Contracts\ManuscriptRepositoryInterface;
use App\Support\BusinessTimezone;
use App\Support\CacheVersion;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * The area's money and customer figures, computed once for everyone (owner,
 * 2026-11-02: the phone's Home showed ~1.2M arrears where the web showed
 * ~700k — the phone summed its own offline copy, every status, plus
 * customers that had left the area). The web dashboard, the phone's Today
 * screen and the phone's Home (via sync pull) all show these:
 *  - arrears: the active customers' arrears for the month (the owner's
 *    2026-08 rule); frozen_arrears: the debt of disconnected / suspended /
 *    passive customers, on its own line;
 *  - the month is the current one, or the latest billed one before the
 *    current month's bills are out;
 *  - the area is the viewer's: an agent's zones, a fenced user's branch,
 *    otherwise the branch picked or the whole company.
 */
class AreaSummaryService
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
        private readonly ManuscriptRepositoryInterface $repository,
    ) {}

    /**
     * @return array{period: string, arrears: string, owing_customers: int, frozen_arrears: string, active_customers: int, disconnected_customers: int, total_customers: int}
     */
    public function forViewer(?string $period = null): array
    {
        $zoneIds = TenantContext::currentZoneIds();
        $branchId = TenantContext::currentBranchId();
        $period ??= $this->billedPeriod($branchId);

        $key = sprintf(
            'area-summary:%s:%s:%s:%s',
            CacheVersion::of(CacheVersion::CUSTOMERS, CacheVersion::MANUSCRIPTS, CacheVersion::PAYMENTS),
            $period,
            $branchId ?? 'all',
            $zoneIds === null ? 'all' : implode('.', $zoneIds),
        );

        return Cache::remember($key, now()->addSeconds(60), function () use ($period, $branchId, $zoneIds): array {
            $filters = ['period' => $period] + ($zoneIds !== null ? ['zone_id' => $zoneIds] : []);
            $summary = $this->manuscripts->summary($filters);
            $breakdown = $this->repository->arrearsBreakdown($filters);

            $customers = fn () => Customer::query()
                ->when($branchId !== null, fn ($query) => $query->where('customers.branch_id', $branchId))
                ->when($zoneIds !== null, fn ($query) => $query->whereIn('customers.zone_id', $zoneIds));

            return [
                'period' => $period,
                'arrears' => (string) $summary['total_arrears'],
                'owing_customers' => $breakdown['owing_customers'],
                'frozen_arrears' => $breakdown['frozen_arrears'],
                'active_customers' => $customers()->where('status', 'active')->count(),
                'disconnected_customers' => $customers()->whereIn('status', ['disconnected', 'suspended'])->count(),
                'total_customers' => $customers()->count(),
            ];
        });
    }

    /** This month, or the latest month with bills while this month's aren't out yet. */
    private function billedPeriod(?int $branchId): string
    {
        $current = BusinessTimezone::currentPeriod();
        $latest = Manuscript::query()
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('period', '<=', $current)
            ->max('period');

        return $latest ?? $current;
    }
}
