<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Repositories\Contracts\ManuscriptRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ManuscriptService
{
    public function __construct(
        private readonly ManuscriptRepositoryInterface $manuscripts,
        private readonly ZoneRepositoryInterface $zones,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'period', 'zone_uuid', 'status'.
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->manuscripts->paginate($this->scopedFilters($filters), $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters  Same keys as list().
     * @return array{total_customers: int, total_bill: string, total_arrears: string, total_credit: string, total_collected: string, collection_rate: float}
     */
    public function summary(array $filters): array
    {
        return $this->summaryFor($this->scopedFilters($filters));
    }

    /**
     * @return Collection<int, Manuscript>
     */
    public function history(Customer $customer): Collection
    {
        return $this->manuscripts->historyForCustomer($customer->id);
    }

    /**
     * Everything needed to render the manuscript register PDF
     * (resources/views/pdf/manuscript.blade.php): the full, unpaginated set
     * of matching manuscripts, the same summary totals the JSON index
     * returns, the resolved period, and the tenant's company record for the
     * header. See business-rules.md section 4.
     *
     * @param  array<string, mixed>  $filters  Same keys as list().
     * @return array{period: string, company: ?Company, manuscripts: Collection<int, Manuscript>, summary: array<string, mixed>}
     */
    public function exportData(array $filters): array
    {
        $scoped = $this->scopedFilters($filters);

        return [
            'period' => $scoped['period'],
            'company' => Company::query()->first(),
            'manuscripts' => $this->manuscripts->all($scoped),
            'summary' => $this->summaryFor($scoped),
        ];
    }

    /**
     * Everything needed to render a single customer's bill slip
     * (resources/views/pdf/bill.blade.php). See business-rules.md section 3.
     *
     * Resolves the manuscript for the given period, or the customer's most
     * recently calculated manuscript when no period is given.
     *
     * @return array{company: ?Company, customer: Customer, manuscript: Manuscript, period: string, period_label: string, deadline: string}
     */
    public function billData(Customer $customer, ?string $period): array
    {
        if ($period !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw ValidationException::withMessages(['period' => ['The period must be in YYYY-MM format.']]);
        }

        $manuscript = $period !== null
            ? $this->manuscripts->forCustomerAndPeriod($customer->id, $period)
            : $customer->latestManuscript;

        if (! $manuscript) {
            throw new ModelNotFoundException(
                $period !== null
                    ? "No manuscript found for customer [{$customer->uuid}] for period [{$period}]."
                    : "No manuscript found for customer [{$customer->uuid}]."
            );
        }

        $customer->loadMissing('zone');

        $billPeriod = $period ?? $manuscript->period;
        $periodLabel = Carbon::createFromFormat('Y-m', $billPeriod)->format('F Y');

        return [
            'company' => Company::query()->first(),
            'customer' => $customer,
            'manuscript' => $manuscript,
            'period' => $billPeriod,
            'period_label' => $periodLabel,
            'deadline' => "05 {$periodLabel}",
        ];
    }

    /**
     * @param  array<string, mixed>  $scopedFilters  Already validated/resolved via scopedFilters().
     * @return array{total_customers: int, total_bill: string, total_arrears: string, total_credit: string, total_collected: string, collection_rate: float}
     */
    private function summaryFor(array $scopedFilters): array
    {
        $aggregates = $this->manuscripts->aggregates($scopedFilters);

        $aggregates['collection_rate'] = $this->collectionRate(
            $aggregates['total_collected'],
            $aggregates['total_bill']
        );

        return $aggregates;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function scopedFilters(array $filters): array
    {
        $filters['period'] ??= Carbon::now()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $filters['period'])) {
            throw ValidationException::withMessages(['period' => ['The period must be in YYYY-MM format.']]);
        }

        if (! empty($filters['zone_uuid'])) {
            $zone = $this->zones->findByUuid($filters['zone_uuid']);

            if (! $zone) {
                throw ValidationException::withMessages(['zone_uuid' => ['The selected zone does not exist.']]);
            }

            $filters['zone_id'] = $zone->id;
        }

        return $filters;
    }

    private function collectionRate(string $collected, string $billed): float
    {
        if (bccomp($billed, '0.00', 2) <= 0) {
            return 0.0;
        }

        return round(((float) $collected / (float) $billed) * 100, 1);
    }
}
