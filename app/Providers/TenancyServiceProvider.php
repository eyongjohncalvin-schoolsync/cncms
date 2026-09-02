<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    public function events()
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                    Jobs\SeedDatabase::class,

                    // Self-service registration: after the schema is built +
                    // seeded, write the owner's tenant_users membership and
                    // their submitted company details (stashed on
                    // tenants.data by WorkspaceProvisioningService). No-op
                    // for Landlord-created tenants.
                    \App\Jobs\FinalizeWorkspaceProvisioning::class,

                ])->send(function (Events\TenantCreated $event) {
                    return $event->tenant;
                })->shouldBeQueued(true), // Queued: creating a tenant runs every tenant migration; doing that inline blows past the HTTP/FastCGI timeout on a pooled remote DB. Needs the queue worker running.
            ],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            Events\TenantDeleted::class => [
                JobPipeline::make([
                    Jobs\DeleteDatabase::class,
                ])->send(function (Events\TenantDeleted $event) {
                    return $event->tenant;
                })->shouldBeQueued(false), // `false` by default, but you probably want to make this `true` for production.
            ],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register()
    {
        //
    }

    public function boot()
    {
        $this->bootEvents();
        $this->mapRoutes();

        $this->makeTenancyMiddlewareHighestPriority();
    }

    protected function bootEvents()
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    protected function mapRoutes()
    {
        // CNCMS resolves tenancy from the authenticated user's tenant_users
        // membership (App\Http\Middleware\ResolveTenant / ResolveTenantWeb),
        // never from the request domain/subdomain/path. There are no
        // domain-identified tenant routes — `routes/tenant.php` is gone and
        // `config('tenancy.routes')` is false, so this is a no-op. Guarded
        // both ways so a re-added stub file can't reintroduce
        // `InitializeTenancyByDomain` (which 500s on any host with no
        // `domains` row — e.g. the production URL).
        if (! ($this->app['config']['tenancy.routes'] ?? true)) {
            return;
        }

        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }

    protected function makeTenancyMiddlewareHighestPriority()
    {
        $tenancyMiddleware = [
            // Even higher priority than the initialization middleware
            Middleware\PreventAccessFromCentralDomains::class,

            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }
}
