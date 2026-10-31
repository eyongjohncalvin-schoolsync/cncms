<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The "Cable companies running on CNCMS" strip on the public home page.
 *
 * A company is shown while it is approved, active, and listed (it agreed at
 * registration, or the landlord switched it on). Any change to a tenant
 * clears the cache (Tenant::booted()), so a new company appears and a
 * removed one disappears on the next page view. Oldest first.
 */
class HomepageCompaniesService
{
    private const string CACHE_KEY = 'site:homepage-companies';

    public const string LOGO_DISK = 'public';

    /**
     * @return list<array{name: string, caption: string|null, logo_url: string|null, initials: string}>
     */
    public function list(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), fn () => Tenant::query()
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Tenant $tenant) => $tenant->show_on_homepage && $tenant->is_active && $tenant->isApproved())
            ->map(fn (Tenant $tenant) => [
                'name' => (string) $tenant->name,
                'caption' => $tenant->homepage_caption ?: null,
                'logo_url' => $tenant->homepage_logo ? Storage::disk(self::LOGO_DISK)->url($tenant->homepage_logo) : null,
                'initials' => self::initials((string) $tenant->name),
            ])
            ->values()
            ->all());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * "MDCN" stays "MDCN"; "Meme Vision Cable" becomes "MVC".
     */
    public static function initials(string $name): string
    {
        $name = trim($name);

        if ($name !== '' && mb_strlen($name) <= 5 && ! str_contains($name, ' ')) {
            return mb_strtoupper($name);
        }

        return Str::of($name)
            ->explode(' ')
            ->filter()
            ->take(3)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');
    }
}
