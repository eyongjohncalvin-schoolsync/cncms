<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\TenantContext;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['branch_id', 'name', 'location', 'head_office', 'email', 'phone', 'tech_number', 'momo_number', 'momo_name', 'reconnection_fine', 'arrears_second_approval_threshold', 'rccm_number', 'niu', 'default_locale', 'bill_template', 'bills_per_page'])]
#[RouteKey('uuid')]
class Company extends Model implements HasMedia
{
    use Auditable, HasUuid, InteractsWithMedia;

    /**
     * The three bill card layouts — see resources/views/pdf/bills/
     * classic.blade.php / compact.blade.php / modern.blade.php and this
     * cycle's design review (Settings/BillPrinting.tsx). This codebase has
     * no app/Enums/ directory and doesn't use backed enums — a plain const
     * array + Rule::in() validation (see UpdateBillPrintingRequest) is the
     * established convention here, not the first backed enum.
     */
    public const array BILL_TEMPLATES = ['classic', 'compact', 'modern'];

    /**
     * Valid N-up densities for the bulk bill grid (resources/views/pdf/
     * bills/_grid.blade.php) — 1 bill per sheet, or 2/4 tiled onto one
     * sheet using the 'compact' template.
     */
    public const array BILLS_PER_PAGE_OPTIONS = [1, 2, 4];

    protected function casts(): array
    {
        return [
            'reconnection_fine' => 'decimal:2',
            'arrears_second_approval_threshold' => 'decimal:2',
            'bills_per_page' => 'integer',
        ];
    }

    /**
     * Nullable — see 2026_08_24_160020_add_branch_id_to_companies_table.php
     * and branches-and-locations.md section 3. `companies` becoming
     * branch-scoped is forward-looking (each branch could get its own
     * Company row later); today every tenant still has exactly one Company
     * row, backfilled to "Main Branch".
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Single-file 'logo' collection — see
     * .ai/skills/cncms/cncms-context/references/company-settings.md.
     * singleFile() means each new upload (via
     * SettingsCompanyController::update()'s addMediaFromRequest()->
     * toMediaCollection('logo')) automatically replaces/deletes the
     * previous logo, so there's never more than one media row for this
     * collection per Company.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml']);
    }

    /**
     * Base64 `data:` URI for the current logo, or null if none is set.
     * dompdf (used for bill/manuscript PDFs) can't reliably fetch a
     * storage: URL mid-render (no guaranteed network/auth context), so
     * PDF views embed the logo this way instead of via getFirstMediaUrl().
     * See resources/views/pdf/bill.blade.php / pdf/manuscript.blade.php.
     */
    public function logoDataUri(): ?string
    {
        $media = $this->getFirstMedia('logo');

        if (! $media instanceof Media || ! is_file($media->getPath())) {
            return null;
        }

        $contents = file_get_contents($media->getPath());

        if ($contents === false) {
            return null;
        }

        return 'data:'.$media->mime_type.';base64,'.base64_encode($contents);
    }

    /**
     * Cache key PREFIX for the cached() singleton lookup below. Stancl
     * Tenancy's CacheTenancyBootstrapper auto-prefixes this per-tenant once
     * tenancy() has been initialized, so this plain string key is safe to
     * share across tenants — do not add a tenant id to it manually.
     *
     * Branch-suffixed via cacheKey() below rather than used bare: `companies`
     * is nullable-branch_id and forward-looking toward one Company row per
     * branch (see the branch() relation's doc comment above) — today every
     * tenant still has exactly one row, so a flat key was harmless, but the
     * moment a second branch-scoped Company row exists, a flat key would
     * serve one branch's template/logo/MOMO number on another branch's
     * printed bill. Same "branch fence baked into the cache key" pattern as
     * App\Services\CustomerService::list()/findOrFail() and
     * App\Services\ManuscriptService's cache keys.
     */
    public const string CACHE_KEY = 'company:current';

    /**
     * Branch-fenced cache key for cached() below.
     * TenantContext::currentBranchId() resolves defensively (see its own
     * doc comment) so this is safe to call even outside an HTTP request.
     */
    public static function cacheKey(): string
    {
        return self::CACHE_KEY.':'.(TenantContext::currentBranchId() ?? 'all');
    }

    /**
     * Cached read of the single Company settings row. Company is a
     * single-row settings table (see TenantDatabaseSeeder::seedCompany)
     * that's read on nearly every settings page load and on every
     * bill/manuscript PDF export, so it's cached for an hour and
     * invalidated explicitly via forgetCache() below.
     */
    public static function cached(): ?self
    {
        return Cache::remember(self::cacheKey(), now()->addHour(), fn () => static::query()->first());
    }

    /**
     * Forgets both this actor's branch-fenced cache entry AND the
     * unrestricted ('all') entry — mirroring App\Services\CustomerService::
     * forgetShowCache()'s two-key forget, since a writer (always super/admin
     * per CompanyPolicy::update(), who may or may not be branch-fenced) and
     * a reader can resolve to different branch fences. Any other
     * branch-fenced caller's cached copy is left to expire via cached()'s
     * 1-hour TTL, the same staleness tradeoff CustomerService already
     * accepts.
     */
    public static function forgetCache(): void
    {
        Cache::forget(self::cacheKey());
        Cache::forget(self::CACHE_KEY.':all');
    }
}
