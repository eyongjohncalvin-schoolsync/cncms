<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\CustomerServiceSelection;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Service;
use App\Models\ServiceVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single writer of the `customer_service` pivot and, through it, of the
 * cached `customers.bill` projection (services.md sections 2 & 4).
 *
 * Every mutation goes through here so `customers.bill` is ALWAYS rewritten
 * to `sum(customer_service.price)` in the same DB transaction that changes
 * the pivot — treat any code path that writes `customers.bill` without also
 * reconciling the pivot as a bug. Rows are operated as first-class
 * App\Models\CustomerSubscription models (create/update/delete), never
 * belongsToMany attach/detach, so each change fires the Auditable model
 * events.
 */
class CustomerSubscriptionService
{
    /**
     * Diff this customer's subscriptions to exactly match $selections —
     * base services AND, one level deep, their variants (services.md
     * section 4, e.g. a specific TV channel broadcast) — attaching new
     * rows, updating a changed price, and detaching removed ones, then
     * recomputing `bill`. One transaction.
     *
     * Keyed internally by `(service_id, variant_id ?? 0)` rather than
     * `service_id` alone, since a service can now be represented by more
     * than one pivot row (its base row plus one per selected variant).
     *
     * @param  list<CustomerServiceSelection>  $selections
     */
    public function sync(Customer $customer, array $selections): void
    {
        if ($selections === []) {
            // A customer with no services would have bill = 0, which the
            // billing engine treats as "free" — almost never intended.
            throw ValidationException::withMessages([
                'services' => ['Select at least one service.'],
            ]);
        }

        // Resolve every uuid up front, rejecting duplicates/unknowns/
        // cross-references here rather than mid-loop.
        $servicesByUuid = Service::query()
            ->whereIn('uuid', array_map(fn (CustomerServiceSelection $s): string => $s->serviceUuid, $selections))
            ->get()
            ->keyBy('uuid');

        $variantUuids = array_values(array_filter(array_map(
            fn (CustomerServiceSelection $s): ?string => $s->serviceVariantUuid,
            $selections,
        )));

        $variantsByUuid = $variantUuids === []
            ? collect()
            : ServiceVariant::query()->whereIn('uuid', $variantUuids)->get()->keyBy('uuid');

        /** @var array<string, array{service_id: int, service_variant_id: ?int, price: string}> $desired */
        $desired = [];

        // service_id => true for every service that has a base (no-variant)
        // selection in this same set — checked below to enforce "a variant
        // requires its base service also selected."
        $hasBase = [];

        foreach ($selections as $selection) {
            $service = $servicesByUuid->get($selection->serviceUuid);

            if ($service === null) {
                throw ValidationException::withMessages([
                    'services' => ["The selected service ({$selection->serviceUuid}) does not exist."],
                ]);
            }

            $variantId = null;

            if ($selection->serviceVariantUuid !== null) {
                $variant = $variantsByUuid->get($selection->serviceVariantUuid);

                if ($variant === null) {
                    throw ValidationException::withMessages([
                        'services' => ["The selected option ({$selection->serviceVariantUuid}) does not exist."],
                    ]);
                }

                if ($variant->service_id !== $service->id) {
                    throw ValidationException::withMessages([
                        'services' => ["\"{$variant->name}\" is not an option of \"{$service->name}\"."],
                    ]);
                }

                $variantId = $variant->id;
            } else {
                $hasBase[$service->id] = true;
            }

            $key = $service->id.':'.($variantId ?? 0);

            if (array_key_exists($key, $desired)) {
                throw ValidationException::withMessages([
                    'services' => ['The same service (or option) can only be selected once.'],
                ]);
            }

            $desired[$key] = [
                'service_id' => $service->id,
                'service_variant_id' => $variantId,
                'price' => $this->normalizePrice($selection->price),
            ];
        }

        // The section 4 invariant: a variant's parent service must also be
        // selected as a base row in this same set — you can't hold "the
        // news channel add-on" without holding TV itself.
        foreach ($desired as $row) {
            if ($row['service_variant_id'] !== null && ! ($hasBase[$row['service_id']] ?? false)) {
                $serviceName = $servicesByUuid->firstWhere('id', $row['service_id'])?->name ?? 'this service';

                throw ValidationException::withMessages([
                    'services' => ["Select \"{$serviceName}\" itself before adding one of its options."],
                ]);
            }
        }

        DB::transaction(function () use ($customer, $desired): void {
            $existing = $customer->subscriptions()->get()
                ->keyBy(fn (CustomerSubscription $row): string => $row->service_id.':'.($row->service_variant_id ?? 0));

            foreach ($desired as $key => $row) {
                $existingRow = $existing->get($key);

                if ($existingRow === null) {
                    $customer->subscriptions()->create($row);

                    continue;
                }

                if ($this->normalizePrice((string) $existingRow->price) !== $row['price']) {
                    $existingRow->update(['price' => $row['price']]);
                }
            }

            foreach ($existing as $key => $row) {
                if (! array_key_exists($key, $desired)) {
                    $row->delete();
                }
            }

            $this->recomputeBill($customer);
        });
    }

    /**
     * `customers.bill = sum(customer_service.price)`. Also called by the
     * backfill and by "apply catalogue price to all subscribers". Assumes it
     * is already inside a transaction with the pivot writes.
     */
    public function recomputeBill(Customer $customer): void
    {
        $sum = (string) $customer->subscriptions()->sum('price');

        $customer->bill = $this->normalizePrice($sum === '' ? '0' : $sum);
        $customer->save();
    }

    /**
     * The bulk bill-update tool's per-customer write (services.md section
     * 8): the customer holds exactly one service — set that pivot row's
     * price and recompute `bill`. If the customer somehow has no
     * subscription at all (a pre-feature row that slipped past the
     * backfill), fall back to attaching the default service at $price so
     * the operation still lands and the invariant is restored.
     */
    public function setSingleServicePrice(Customer $customer, string $price): void
    {
        $price = $this->normalizePrice($price);

        DB::transaction(function () use ($customer, $price): void {
            $row = $customer->subscriptions()->first();

            if ($row === null) {
                $default = Service::default();

                if ($default === null) {
                    throw ValidationException::withMessages([
                        'services' => ['No default service is configured. Set one in Settings -> Services.'],
                    ]);
                }

                $customer->subscriptions()->create([
                    'service_id' => $default->id,
                    'price' => $price,
                ]);
            } elseif ($this->normalizePrice((string) $row->price) !== $price) {
                $row->update(['price' => $price]);
            }

            $this->recomputeBill($customer);
        });
    }

    /**
     * The default selection for a brand-new customer whose request carried
     * no `services` key (services.md section 4): the `is_default` service,
     * priced at $bill if the caller supplied one — a still-unmigrated
     * caller sending the legacy raw `bill` field alone (§6 hasn't reached
     * every request/controller yet) must NOT have it silently clobbered
     * back to the service's 0.00 catalogue seed price. Falls back to the
     * catalogue price only when no legacy bill was given either.
     */
    public function defaultSelection(?string $bill = null): CustomerServiceSelection
    {
        $default = Service::default();

        if ($default === null) {
            throw ValidationException::withMessages([
                'services' => ['No default service is configured. Set one in Settings -> Services.'],
            ]);
        }

        return new CustomerServiceSelection($default->uuid, $bill ?? (string) $default->price);
    }

    /**
     * SettingsServiceController's "apply new price to all N current
     * subscribers" — set every BASE (`service_variant_id IS NULL`) pivot
     * row for $service to the catalogue price and recompute each affected
     * customer's bill. A service's variants have their own independent
     * catalogue price (services.md section 4) and are repriced separately
     * via applyVariantPriceToSubscribers() — this never touches them.
     * Returns the number of subscriptions repriced.
     *
     * Run synchronously: even the real tenant's ~550 customers hold one or
     * two rows each, so the worst realistic batch is a few hundred single
     * UPDATEs + one bill recompute apiece — well within a request. If a
     * tenant ever grows past that, this is the seam to move onto the
     * bulk-bill batching pattern.
     */
    public function applyCataloguePriceToSubscribers(Service $service): int
    {
        return $this->applyPriceToRows(
            CustomerSubscription::query()->where('service_id', $service->id)->whereNull('service_variant_id'),
            (string) $service->price,
        );
    }

    /**
     * The variant equivalent of applyCataloguePriceToSubscribers() — set
     * every pivot row pinned to $variant to its current catalogue price and
     * recompute each affected customer's bill.
     */
    public function applyVariantPriceToSubscribers(ServiceVariant $variant): int
    {
        return $this->applyPriceToRows(
            CustomerSubscription::query()->where('service_variant_id', $variant->id),
            (string) $variant->price,
        );
    }

    /**
     * @param  Builder<CustomerSubscription>  $query
     */
    private function applyPriceToRows(Builder $query, string $price): int
    {
        $price = $this->normalizePrice($price);

        return DB::transaction(function () use ($query, $price): int {
            $rows = $query->with('customer')->get();

            $affected = 0;

            foreach ($rows as $row) {
                if ($this->normalizePrice((string) $row->price) !== $price) {
                    $row->update(['price' => $price]);
                    $affected++;
                }

                if ($row->customer !== null) {
                    $this->recomputeBill($row->customer);
                }
            }

            return $affected;
        });
    }

    private function normalizePrice(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
