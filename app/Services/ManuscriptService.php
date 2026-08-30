<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Zone;
use App\Repositories\Contracts\ManuscriptRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ManuscriptService
{
    /**
     * Manuscript rows for a given period never change except when
     * manuscript:calculate re-runs for that period (once a month in
     * practice — see ManuscriptCalculate::forgetSummaryCache() call), so a
     * generous TTL is an easy tradeoff for the aggregate queries below.
     */
    private const CACHE_TTL_MINUTES = 10;

    public function __construct(
        private readonly ManuscriptRepositoryInterface $manuscripts,
        private readonly ZoneRepositoryInterface $zones,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'period', 'zone_uuid', 'status', 'search'.
     *                                         'search' matches the customer's name (ILIKE, partial)
     *                                         or phone (LIKE, partial) — same shape as the Customers
     *                                         list. It is folded into listCacheKey()/summaryCacheKey()
     *                                         via scopedFilters() (json_encode'd whole), so a searched
     *                                         view never serves a cached unsearched page.
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $scoped = $this->scopedFilters($filters);
        $page = Paginator::resolveCurrentPage() ?: 1;

        return Cache::remember(
            $this->listCacheKey($scoped, $perPage, $page),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): LengthAwarePaginator => $this->manuscripts->paginate($scoped, $perPage),
        );
    }

    /**
     * total_bill/total_arrears/total_credit/total_collected (and therefore
     * collection_rate, derived from the last two) are scoped to ACTIVE
     * customers only — see ManuscriptRepository::aggregates()'s doc comment
     * for why. total_customers is NOT scoped that way; it counts every
     * customer matching the filters regardless of status.
     *
     * @param  array<string, mixed>  $filters  Same keys as list().
     * @return array{total_customers: int, total_bill: string, total_arrears: string, total_credit: string, total_collected: string, collection_rate: float}
     */
    public function summary(array $filters): array
    {
        $scoped = $this->scopedFilters($filters);

        return Cache::remember(
            $this->summaryCacheKey($scoped),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->summaryFor($scoped),
        );
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
            'company' => Company::cached(),
            'manuscripts' => $this->manuscripts->all($scoped),
            'summary' => $this->summaryFor($scoped),
        ];
    }

    /**
     * Everything needed to render a single customer's bill slip
     * (resources/views/pdf/bills/{classic,compact,modern}.blade.php via
     * pdf/bills/show.blade.php). See business-rules.md section 3.
     *
     * Resolves the manuscript for the given period, or the customer's most
     * recently calculated manuscript when no period is given.
     *
     * @return array{company: ?Company, customer: Customer, manuscript: Manuscript, period: string, period_label: string, deadline: string, account_code: string, bill_number: string, logo_data_uri: ?string}
     */
    public function billData(Customer $customer, ?string $period): array
    {
        $manuscript = $this->resolveManuscript($customer, $period);
        $company = Company::cached();

        return $this->buildBillData($customer, $manuscript, $period, $company, $company?->logoDataUri());
    }

    /**
     * Batch counterpart of billData() for the bulk N-up bill grid
     * (resources/views/pdf/bills/_grid.blade.php) — deliberately NOT just a
     * loop calling billData() per customer, because billData() resolves
     * Company::cached()->logoDataUri() itself on every call. logoDataUri()
     * re-reads and re-base64-encodes the logo FILE from disk on every
     * invocation (it isn't itself cached — only the Company row's plain
     * columns are, via Company::cached()'s Cache::remember), so calling it
     * once per customer in a bulk run repeats that multi-KB file read/encode
     * once per customer for the exact same bytes. This resolves the company
     * and its logo data URI exactly ONCE for the whole batch and threads the
     * same values through every bill.
     *
     * Customers with no manuscript for the resolved period are silently
     * skipped (not thrown, unlike billData()) — a bulk render over many
     * customers shouldn't abort the whole batch because one customer has no
     * manuscript yet.
     *
     * @param  iterable<int, Customer>  $customers
     * @return array<int, array{company: ?Company, customer: Customer, manuscript: Manuscript, period: string, period_label: string, deadline: string, account_code: string, bill_number: string, logo_data_uri: ?string}>
     */
    public function billDataForCustomers(iterable $customers, ?string $period): array
    {
        $company = Company::cached();
        $logoDataUri = $company?->logoDataUri();

        $bills = [];

        foreach ($customers as $customer) {
            try {
                $manuscript = $this->resolveManuscript($customer, $period);
            } catch (ModelNotFoundException) {
                continue;
            }

            $bills[] = $this->buildBillData($customer, $manuscript, $period, $company, $logoDataUri);
        }

        return $bills;
    }

    /**
     * Bill data for the Settings > Bill Printing live-preview PDF (GET
     * /settings/bill-printing/preview/{template} —
     * SettingsBillPrintingController::preview()). Uses the tenant's own
     * first real customer with a manuscript if one exists (the most honest
     * preview — real data, real numbers), or an in-memory placeholder
     * customer/manuscript (never persisted) when the tenant has none yet, so
     * the preview always renders something. `is_sample` tells the templates
     * whether to show a "SAMPLE" watermark for the placeholder case.
     *
     * @return array{company: ?Company, customer: Customer, manuscript: Manuscript, period: string, period_label: string, deadline: string, account_code: string, bill_number: string, logo_data_uri: ?string, is_sample: bool}
     */
    public function sampleBillData(): array
    {
        $company = Company::cached();
        $logoDataUri = $company?->logoDataUri();

        $customer = Customer::query()->with('zone')->whereHas('manuscripts')->orderBy('id')->first();

        if ($customer) {
            $manuscript = $customer->latestManuscript;

            return [
                ...$this->buildBillData($customer, $manuscript, null, $company, $logoDataUri),
                'is_sample' => false,
            ];
        }

        $placeholderZone = new Zone(['name' => 'SAMPLE ZONE', 'town' => 'KUMBA 3']);
        $placeholderCustomer = new Customer([
            'name' => 'Sample Customer',
            'location' => 'Sample Location',
            'bill' => '2500.00',
            'phone' => '677000000',
            'level' => 'normal',
            'status' => 'active',
        ]);
        $placeholderCustomer->id = 0;
        $placeholderCustomer->setRelation('zone', $placeholderZone);

        $placeholderManuscript = new Manuscript([
            'bill' => '2500.00',
            'total_arrears' => '1500.00',
            'credit' => '0.00',
            'total_bill' => '4000.00',
            'period' => Carbon::now()->format('Y-m'),
        ]);

        return [
            ...$this->buildBillData($placeholderCustomer, $placeholderManuscript, null, $company, $logoDataUri),
            'is_sample' => true,
        ];
    }

    /**
     * Shared manuscript-resolution logic behind billData() and
     * billDataForCustomers() — validates $period, then resolves the
     * customer's manuscript for it (or their latest one when $period is
     * null), throwing ModelNotFoundException when none exists.
     */
    private function resolveManuscript(Customer $customer, ?string $period): Manuscript
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

        return $manuscript;
    }

    /**
     * Assembles the final per-bill data array shared by billData(),
     * billDataForCustomers(), and sampleBillData() — the single place that
     * builds account_code/bill_number/period_label/deadline so all three
     * callers (and therefore both the single-print flow and the bulk grid)
     * render identically shaped data.
     */
    private function buildBillData(Customer $customer, Manuscript $manuscript, ?string $period, ?Company $company, ?string $logoDataUri): array
    {
        $customer->loadMissing('zone');

        $billPeriod = $period ?? $manuscript->period;
        $periodLabel = Carbon::createFromFormat('Y-m', $billPeriod)->format('F Y');

        return [
            'company' => $company,
            'customer' => $customer,
            'manuscript' => $manuscript,
            'period' => $billPeriod,
            'period_label' => $periodLabel,
            'deadline' => "05 {$periodLabel}",
            'account_code' => $this->accountCode($customer),
            'bill_number' => $this->billNumber($customer, $billPeriod),
            'logo_data_uri' => $logoDataUri,
        ];
    }

    /**
     * A readable, dictatable account code — NOT substr($customer->uuid, 0,
     * 8), the old bill.blade.php/manuscript.blade.php's unreadable hex
     * fragment. No existing "customer code" concept exists anywhere else in
     * this codebase (checked: Customer has no `code` column/accessor), so
     * this invents one: {zone-prefix}-{zero-padded customer id}, e.g.
     * "THR01-0042" for a customer in zone "THR01 (3/CORNERS)". The zone
     * prefix is derived from the zone name (already the de facto zone code
     * in real data — see zones like "THR01 (3/CORNERS)", "AR01(JUNCTION)")
     * by stripping non-alphanumerics and taking the first 6 characters, so
     * it survives the free-text " (Area Name)" suffix zone names carry.
     * Purely a DISPLAY-layer value — does not touch customers.uuid or any
     * routing/lookup scheme.
     */
    private function accountCode(Customer $customer): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $customer->zone?->name ?? ''), 0, 6));

        return sprintf('%s-%04d', $prefix !== '' ? $prefix : 'ACCT', $customer->id);
    }

    /**
     * A stable, referenceable bill number, fully derived from existing data
     * (tenant id + billing period + customer id) rather than a new DB
     * column/sequence — this codebase has a stated preference against
     * unnecessary new state, and this triple is already unique per bill
     * (one manuscript per customer per period). Format:
     * "{TENANT}-{YYYYMM}-{customer id, 6 digits}", e.g.
     * "SWECOM-202608-000042".
     */
    private function billNumber(Customer $customer, string $period): string
    {
        $tenantCode = strtoupper((string) (tenant()?->id ?? 'TEN'));

        return sprintf('%s-%s-%06d', $tenantCode, str_replace('-', '', $period), $customer->id);
    }

    /**
     * Invalidates the cached unfiltered summary() for a period. Called by
     * ManuscriptCalculate once a period's calculation run completes
     * (success or partial) — that command is the only writer of
     * `manuscripts` rows, so this is the one place a cached summary can go
     * stale outright rather than just age out.
     *
     * Only the base unfiltered key (no zone_uuid/status) is forgotten here.
     * The zone_uuid/status filter-permutation key space makes exact
     * per-filter forgetting impractical, so this deliberately only covers
     * the common case — Dashboard and the unfiltered Manuscripts view.
     * A zone/status-filtered summary(), or any cached list() page, can show
     * data up to CACHE_TTL_MINUTES stale after a recalculation; the TTL is
     * what bounds that window, not this invalidation call.
     */
    public function forgetSummaryCache(string $period): void
    {
        Cache::forget($this->summaryCacheKey($this->scopedFilters(['period' => $period])));
    }

    /**
     * Branch fence baked into every key below (via
     * TenantContext::currentBranchId(), not a constructor dependency — see
     * this class's constructor doc comment for why) for the same reason as
     * App\Services\CustomerService::list(): ManuscriptRepository's queries
     * are now branch-scoped, so two callers with identical $scopedFilters
     * but different effective branches must not share a cache entry.
     * forgetSummaryCache() below only ever forgets the unrestricted ('all')
     * key (it runs from the manuscript:calculate console command, outside
     * any tenant HTTP request, where currentBranchId() always resolves to
     * null) — any branch-specific cached summary is left to expire via
     * CACHE_TTL_MINUTES instead, the same pre-existing staleness tradeoff
     * this method's own doc comment already documents for the zone/status
     * filter permutations.
     */
    private function summaryCacheKey(array $scopedFilters): string
    {
        return 'manuscripts:summary:'.$scopedFilters['period'].':'.(TenantContext::currentBranchId() ?? 'all').':'
            .md5(json_encode($scopedFilters));
    }

    private function listCacheKey(array $scopedFilters, int $perPage, int $page): string
    {
        return 'manuscripts:list:'.$scopedFilters['period'].':'.(TenantContext::currentBranchId() ?? 'all').':'
            .md5(json_encode([$scopedFilters, $perPage, $page]));
    }

    /**
     * See summary()'s doc comment: total_bill/total_arrears/total_credit/
     * total_collected/collection_rate below are active-customers-only.
     *
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
