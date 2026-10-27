<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\CacheVersion;

/**
 * Bumps the model's CacheVersion names whenever a row is saved, deleted or
 * restored, so every cached screen built on it is retired (see CacheVersion).
 * The model lists its names in cacheVersions().
 */
trait BumpsCacheVersion
{
    /** @return list<string> CacheVersion names this model's rows feed */
    abstract public function cacheVersions(): array;

    public static function bootBumpsCacheVersion(): void
    {
        $bump = static fn (self $model) => CacheVersion::bump(...$model->cacheVersions());

        static::saved($bump);
        static::deleted($bump);

        if (method_exists(static::class, 'restored')) {
            static::restored($bump);
        }
    }
}
