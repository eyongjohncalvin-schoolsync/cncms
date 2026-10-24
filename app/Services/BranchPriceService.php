<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchPrice;
use App\Models\InstallationOffer;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\BranchPrices;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A branch's own prices (branches v2 §4.6, D4): set or hand back to
 * headquarters a price for a service, a service option or an installation
 * offer — always with a reason, kept as history — and optionally reprice the
 * branch's current subscribers, the way headquarters' "apply to subscribers"
 * does for the catalogue.
 */
class BranchPriceService
{
    public const string TARGET_SERVICE = 'service';

    public const string TARGET_VARIANT = 'variant';

    public const string TARGET_OFFER = 'offer';

    public const array TARGETS = [self::TARGET_SERVICE, self::TARGET_VARIANT, self::TARGET_OFFER];

    public function __construct(
        private readonly CustomerSubscriptionService $subscriptions,
    ) {}

    /**
     * @param  ?string  $price  null hands the target back to headquarters' price
     * @return array{change: BranchPrice, repriced: int}
     */
    public function set(Branch $branch, string $targetType, string $targetUuid, ?string $price, string $reason, User $actor, bool $applyToSubscribers): array
    {
        if ($branch->is_headquarters) {
            throw ValidationException::withMessages(['branch' => 'Headquarters prices are the catalogue — change them in Settings → Services.']);
        }

        $target = $this->resolveTarget($targetType, $targetUuid);

        return DB::transaction(function () use ($branch, $target, $price, $reason, $actor, $applyToSubscribers): array {
            $change = BranchPrice::query()->create([
                'branch_id' => $branch->id,
                'service_id' => $target instanceof Service ? $target->id : null,
                'service_variant_id' => $target instanceof ServiceVariant ? $target->id : null,
                'installation_offer_id' => $target instanceof InstallationOffer ? $target->id : null,
                'price' => $price,
                'reason' => $reason,
                'set_by' => $actor->id,
            ]);

            BranchPrices::flush();
            $repriced = 0;

            if ($applyToSubscribers && ! $target instanceof InstallationOffer) {
                $prices = BranchPrices::for($branch->id);
                $repriced = $target instanceof Service
                    ? $this->subscriptions->applyPriceToBranchSubscribers($branch->id, $target->id, null, $prices->service($target))
                    : $this->subscriptions->applyPriceToBranchSubscribers($branch->id, $target->service_id, $target->id, $prices->variant($target));
            }

            return ['change' => $change, 'repriced' => $repriced];
        });
    }

    /**
     * The branch's latest price changes, newest first — its price history.
     *
     * @return Collection<int, BranchPrice>
     */
    public function history(Branch $branch, int $limit = 30): Collection
    {
        return BranchPrice::query()
            ->where('branch_id', $branch->id)
            ->with(['service:id,name', 'variant:id,name,service_id', 'variant.service:id,name', 'installationOffer:id,name', 'setBy:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    private function resolveTarget(string $targetType, string $targetUuid): Service|ServiceVariant|InstallationOffer
    {
        $target = match ($targetType) {
            self::TARGET_SERVICE => Service::query()->where('uuid', $targetUuid)->first(),
            self::TARGET_VARIANT => ServiceVariant::query()->where('uuid', $targetUuid)->first(),
            self::TARGET_OFFER => InstallationOffer::query()->where('uuid', $targetUuid)->first(),
            default => null,
        };

        if ($target === null) {
            throw ValidationException::withMessages(['target_uuid' => 'That service, option or offer no longer exists.']);
        }

        return $target;
    }
}
