<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A platform-wide switch a landlord flips at runtime (central schema), read
 * with PlatformSetting::get() — cached as the plain value, never the model.
 * A missing row means the caller's default.
 */
#[Connection('pgsql')]
#[Fillable(['key', 'value', 'updated_by', 'updated_at'])]
class PlatformSetting extends Model
{
    /** Sign-in device block on/off (App\Services\LoginGuard). */
    public const string DEVICE_BLOCK = 'login.device_block';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'updated_at' => 'datetime',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $stored = Cache::rememberForever(self::cacheKey($key), fn (): array => [
            'value' => static::query()->whereKey($key)->value('value'),
        ]);

        return $stored['value'] ?? $default;
    }

    public static function put(string $key, mixed $value, ?User $by = null): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id, 'updated_at' => now()]);

        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return "platform-setting:{$key}";
    }
}
