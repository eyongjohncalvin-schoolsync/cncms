<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper as TaggedCacheTenancyBootstrapper;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Replaces Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper in
 * config/tenancy.php. That stock bootstrapper unconditionally routes every
 * Cache::* call through ->tags([...]) to scope it per tenant (see
 * vendor/stancl/tenancy/src/CacheManager.php::__call()). Laravel's
 * database/file/null cache stores don't extend Illuminate\Cache\TaggableStore,
 * so tags() throws BadMethodCallException("This cache store does not
 * support tagging.") for EVERY cache call, not just explicit tag usage —
 * this broke every Cache::remember()/forget() call added anywhere in the
 * app the moment tenancy.php's default bootstrapper ran with
 * CACHE_STORE=database.
 *
 * Fix: only delegate to the tag-based bootstrapper when the active store
 * actually supports tags (Redis/Memcached/Array — e.g. in production once
 * CACHE_STORE=redis is set). Otherwise, fall back to key-prefixing via the
 * store's setPrefix() (supported by every built-in store, including
 * "database"), which gives equivalent per-tenant cache isolation without
 * requiring tag support. No application code needs to change either way —
 * cache keys stay plain strings; scoping happens transparently here.
 */
class CacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected ?string $originalPrefix = null;

    protected TaggedCacheTenancyBootstrapper $tagged;

    public function __construct(protected Application $app)
    {
        $this->tagged = new TaggedCacheTenancyBootstrapper($app);
    }

    public function bootstrap(Tenant $tenant): void
    {
        $store = Cache::getStore();

        if ($store instanceof TaggableStore) {
            $this->tagged->bootstrap($tenant);

            return;
        }

        if (method_exists($store, 'setPrefix')) {
            $this->originalPrefix = $store->getPrefix();
            $store->setPrefix($this->originalPrefix.'tenant_'.$tenant->getTenantKey().'_');
        }
    }

    public function revert(): void
    {
        $store = Cache::getStore();

        if ($store instanceof TaggableStore) {
            $this->tagged->revert();

            return;
        }

        if ($this->originalPrefix !== null && method_exists($store, 'setPrefix')) {
            $store->setPrefix($this->originalPrefix);
        }

        $this->originalPrefix = null;
    }
}
