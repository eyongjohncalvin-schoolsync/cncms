<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A company service catalogue entry — something the operator sells (TV
 * Service, Internet, VOD, Satellite Hosting…). Every tenant manages its own
 * catalogue. See .claude/skills/cncms-context/references/services.md.
 *
 * `price` is the catalogue monthly price — the default charged when a
 * customer ticks this service. A customer's actual charge lives on the
 * `customer_service` pivot (App\Models\CustomerSubscription) and is
 * independent once set: editing this catalogue price does NOT retro-change
 * existing subscriptions (SettingsServiceController offers an explicit
 * "apply to all subscribers" action for that).
 */
#[Fillable(['name', 'slug', 'price', 'is_default', 'active', 'description', 'sort_order'])]
#[RouteKey('uuid')]
class Service extends Model
{
    use Auditable, HasUuid;

    /**
     * Settings -> Services (services.md section 6-7) lets an operator name
     * a service (e.g. "Premium Support") without ever typing a `slug` —
     * the 4 seeded rows (tv/internet/vod/satellite-hosting) got theirs from
     * the seed migration directly, but anything created through the
     * catalogue screen needs one derived here, since the column is NOT
     * NULL + unique with no DB default. Only fires when the caller didn't
     * already set one (the seed migration's raw DB::table() inserts bypass
     * Eloquent entirely and are unaffected).
     */
    protected static function booted(): void
    {
        static::creating(function (Service $service): void {
            if (! empty($service->slug)) {
                return;
            }

            $base = Str::slug($service->name) ?: 'service';
            $slug = $base;
            $suffix = 2;

            while (static::query()->where('slug', $slug)->exists()) {
                $slug = "{$base}-{$suffix}";
                $suffix++;
            }

            $service->slug = $slug;
        });
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_service')
            ->using(CustomerSubscription::class)
            ->withPivot(['id', 'uuid', 'price', 'service_variant_id'])
            ->withTimestamps();
    }

    /**
     * Priced sub-options one level deep (services.md section 4) — e.g. TV
     * channel broadcasts under the `tv` service. Empty for a service that
     * doesn't offer any; no service is hardcoded to have them.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ServiceVariant::class);
    }

    /** @param  Builder<Service>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @param  Builder<Service>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The pre-ticked service on the add form. Deliberately NOT memoised
     * (an earlier version cached this in a `static` property): Stancl
     * tenancy switches the underlying `tenant` DB connection within a
     * single PHP process — every queued job re-initializes tenancy
     * (App\Services\ManuscriptGenerationBatchService et al.), and so does
     * the test suite across test methods — so a class-level static cache
     * silently leaks tenant A's `Service` row into tenant B's request,
     * pointing `customer_service.service_id` at a row from the wrong
     * schema. This is one cheap indexed query against a handful of rows;
     * not worth that risk. (2026-09-06: exactly this leak made
     * CustomerImportSeedsManuscriptArrearsTest flaky depending on which
     * other tenant's test ran first in the same phpunit process.)
     */
    public static function default(): ?Service
    {
        return self::query()->where('is_default', true)->first();
    }
}
