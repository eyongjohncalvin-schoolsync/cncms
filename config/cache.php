<?php

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "storage", "octane",
    |                    "session", "failover", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'storage' => [
            'driver' => 'storage',
            'disk' => env('CACHE_STORAGE_DISK'),
            'path' => env('CACHE_STORAGE_PATH', 'framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'database',
                'array',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | This value determines the classes that can be unserialized from cache
    | storage. By default, no PHP classes will be unserialized from your
    | cache to prevent gadget chain attacks if your APP_KEY is leaked.
    |
    | app/Services/*.php cache several Service methods that return Eloquent
    | models/collections/paginators directly (e.g. ZoneService::all(),
    | CustomerService::findOrFail(), PaymentService::list()). With this left
    | at `false`, every cache HIT for those keys unserializes as
    | __PHP_Incomplete_Class instead of the real model, which throws a
    | TypeError against the method's declared return type on the very next
    | request within the TTL (confirmed by reproducing it directly via
    | `php artisan tinker` — the first, cache-populating call always works,
    | only the second, cache-hit call fails, which is why this doesn't show
    | up under CACHE_STORE=array in tests). Explicitly allow-listing just the
    | models/collection/paginator classes those cached methods actually
    | return (rather than `true`, which would allow unserializing ANY class)
    | keeps the gadget-chain protection this default exists for while making
    | the caching actually work.
    |
    | Company::class was originally missed here even though
    | App\Models\Company::cached() caches a Company instance the exact same
    | way (`Cache::remember(self::CACHE_KEY, ..., fn () =>
    | static::query()->first())`) — that's the actual root cause of the
    | "Return value must be of type ?App\Models\Company,
    | __PHP_Incomplete_Class returned" production error: the class allow-list
    | mechanism itself was working correctly (it's Laravel core's
    | Illuminate\Cache\CacheManager::getSerializableClasses(), read straight
    | from this config key and passed to DatabaseStore's unserialize(...,
    | ['allowed_classes' => ...])), Company was simply the one cached model
    | not yet on the list. Any new model added to a Cache::remember() closure
    | anywhere in app/Services must be added here too, or it will fail the
    | same way on its first cache hit.
    |
    */

    'serializable_classes' => [
        Agent::class,
        AuditLog::class,
        Company::class,
        Customer::class,
        Expenditure::class,
        ExpenseCategory::class,
        Manuscript::class,
        Payment::class,
        User::class,
        Zone::class,
        Collection::class,
        LengthAwarePaginator::class,
    ],

];
