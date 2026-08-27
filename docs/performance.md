# Performance

## Production caching commands

CNCMS doesn't run these routinely yet (dev environment), but they should be
part of the deploy step for a real, deploy-time perf win — independent of
any application-level caching (e.g. `Company::cached()`):

- `php artisan config:cache` — combines all config files into one cached
  file.
- `php artisan route:cache` — combines all routes into one cached file.
- `php artisan view:cache` — precompiles all Blade views.
- `php artisan event:cache` — caches event-to-listener mappings.

**Caution:** `config:cache` must be re-run after ANY `.env` change, or the
app will silently keep serving stale config (calls to `env()` outside of
`config/*.php` return `null` once cached, and `.env` values already baked
into the cache stay stale until the next `config:cache`).

## `route:cache` prerequisite

`route:cache` requires all routes to be closure-free (`[Controller::class,
'method']`, not `function () { ... }`). Two closure routes currently exist
and must be converted before `route:cache` can be used:

- `routes/web.php` — the root `/` redirect route.
- `routes/tenant.php` — the `/tenant-info` smoke-test route (unused
  domain-based tenancy scaffolding, per its doc comment).
