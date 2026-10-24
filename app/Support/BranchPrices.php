<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use App\Models\InstallationOffer;
use App\Models\Service;
use App\Models\ServiceVariant;
use Illuminate\Support\Facades\DB;

/**
 * A branch's price list (branches v2 §4.6, D4): headquarters' catalogue price
 * unless the branch overrides it (App\Models\BranchPrice). One query per
 * branch per process — the latest change per target — then every lookup is
 * in memory, so pricing a form or a whole billing batch never queries per row.
 *
 * Headquarters (or no branch) is simply the catalogue.
 */
final class BranchPrices
{
    /** @var array<string, self> */
    private static array $memo = [];

    /**
     * @param  array<int, string>  $services  service id => price
     * @param  array<int, string>  $variants  service variant id => price
     * @param  array<int, string>  $offers  installation offer id => fee
     */
    private function __construct(
        private readonly array $services = [],
        private readonly array $variants = [],
        private readonly array $offers = [],
    ) {}

    public static function for(?int $branchId): self
    {
        if ($branchId === null || $branchId === Branch::headquartersId()) {
            return new self;
        }

        $key = (tenant()?->getTenantKey() ?? 'central').':'.$branchId;

        return self::$memo[$key] ??= self::load($branchId);
    }

    /** The prices of the branch being worked in (a fenced user's, or the one being viewed), else headquarters'. */
    public static function current(): self
    {
        return self::for(TenantContext::currentBranchId());
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    public function service(Service $service): string
    {
        return $this->services[$service->id] ?? self::money($service->price);
    }

    public function variant(ServiceVariant $variant): string
    {
        return $this->variants[$variant->id] ?? self::money($variant->price);
    }

    public function offerFee(InstallationOffer $offer): string
    {
        return $this->offers[$offer->id] ?? self::money($offer->fee);
    }

    /** Whether the branch sets its own price for this target. */
    public function overridesService(int $serviceId): bool
    {
        return array_key_exists($serviceId, $this->services);
    }

    public function overridesVariant(int $variantId): bool
    {
        return array_key_exists($variantId, $this->variants);
    }

    public function overridesOffer(int $offerId): bool
    {
        return array_key_exists($offerId, $this->offers);
    }

    private static function load(int $branchId): self
    {
        // Latest change per target; a NULL price there means "back to headquarters".
        $rows = DB::table('branch_prices')
            ->selectRaw('DISTINCT ON (service_id, service_variant_id, installation_offer_id) service_id, service_variant_id, installation_offer_id, price')
            ->where('branch_id', $branchId)
            ->orderByRaw('service_id, service_variant_id, installation_offer_id, id DESC')
            ->get();

        $services = $variants = $offers = [];

        foreach ($rows as $row) {
            if ($row->price === null) {
                continue;
            }

            match (true) {
                $row->service_id !== null => $services[(int) $row->service_id] = self::money($row->price),
                $row->service_variant_id !== null => $variants[(int) $row->service_variant_id] = self::money($row->price),
                default => $offers[(int) $row->installation_offer_id] = self::money($row->price),
            };
        }

        return new self($services, $variants, $offers);
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
