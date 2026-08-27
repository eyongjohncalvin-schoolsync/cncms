<?php

use App\Http\Middleware\EnsureLandlord;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveLocale;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\ResolveTenantWeb;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ResolveLocale runs on every web request (guest routes included,
        // for the session/cookie override — see its docblock), but must run
        // before HandleInertiaRequests::share() reads app()->getLocale()
        // for the shared `locale` prop — hence listed first here. Its
        // actual position relative to ResolveTenantWeb on tenant-scoped
        // routes is enforced below via prependToPriorityList, not by this
        // array order (unlisted middleware like HandleInertiaRequests never
        // get reordered by Illuminate\Routing\SortedMiddleware, but
        // priority-listed ones like ResolveTenantWeb/ResolveLocale do).
        $middleware->web(append: [
            ResolveLocale::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'tenant.web' => ResolveTenantWeb::class,
            'landlord' => EnsureLandlord::class,
        ]);

        // Laravel's built-in middleware priority list runs
        // SubstituteBindings (implicit route-model binding, e.g. the
        // {customer}/{payment}/{agent} route parameters) BEFORE any
        // app-specific middleware that isn't in that list — regardless of
        // where ResolveTenant/ResolveTenantWeb are registered in
        // routes/api.php or routes/web.php. Left unpatched, every route
        // with a tenant-scoped bound parameter would attempt to resolve
        // that model BEFORE tenancy()->initialize() has run, querying the
        // still-default *central* connection instead of the tenant's
        // schema (confirmed empirically: this throws "relation
        // \"customers\" does not exist" on a cold request, since
        // tenancy is never pre-initialized outside of tests). Explicitly
        // sequencing both resolvers immediately before SubstituteBindings
        // guarantees tenancy is live before any implicit model binding
        // query runs, while still running after Authenticate (which
        // appears earlier in the default priority list and which both
        // resolvers depend on for $request->user()).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenantWeb::class,
        );

        // Registered immediately after ResolveTenantWeb (see
        // language-support.md section 4) — needs TenantContext/Company
        // already resolved for the tenant-default locale fallback, and must
        // run before SubstituteBindings so App::setLocale() is set before
        // any FormRequest rules()/messages() validate. Since each call to
        // prependToPriorityList(before: SubstituteBindings, ...) inserts
        // immediately before SubstituteBindings' *current* position, and
        // ResolveTenantWeb was already inserted there above, this call
        // lands ResolveLocale directly after it: [..., ResolveTenantWeb,
        // ResolveLocale, SubstituteBindings, ...] — confirmed via
        // `php artisan route:list -vvv`.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveLocale::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Global safety net: a web/Inertia request should never surface a raw
        // PHP stack trace or a blank/broken page to an end user, and with
        // ~15+ controllers a per-controller try/catch approach would be
        // repetitive and easy to miss on any new endpoint. Centralizing it
        // here in respond() means every controller — present and future —
        // gets this for free. API/JSON requests are unaffected by anything
        // below (shouldRenderJsonWhen() above already gave them Laravel's
        // default JSON error envelope, which strips exception/file/line/trace
        // whenever app.debug is false — see
        // Illuminate\Foundation\Exceptions\Handler::renderExceptionResponse()).
        //
        // Laravel's own Handler::prepareException() already converts
        // ModelNotFoundException -> 404 (NotFoundHttpException) and
        // AuthorizationException -> 403 (AccessDeniedHttpException) before
        // this closure ever runs, so those don't need explicit mapping here
        // — only the "what does a web request do with that status code"
        // question does.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $isApiOrJson = $request->is('api/*') || $request->expectsJson();

            // A raw 429 error page is a poor Inertia UX (it would surface as a
            // full-page crash inside the SPA shell). Redirect back with a
            // flash 'error' instead — the same treatment Inertia apps
            // conventionally give 419 CSRF mismatches — which
            // HandleInertiaRequests::share() already exposes to every page via
            // the shared `flash.error` prop. This only fires for an in-app
            // Inertia navigation (X-Inertia header present); a fresh full-page
            // 429 falls through to the friendly Error page below instead.
            if (! $isApiOrJson && $response->getStatusCode() === 429 && $request->header('X-Inertia')) {
                return back()->with([
                    'error' => 'Too many requests. Please wait a moment and try again.',
                ]);
            }

            // In local/testing (app.debug=true), let Laravel's normal detailed
            // error page render so developers keep full stack traces — don't
            // suppress that. In production, map any other error status for a
            // web/Inertia request to the single friendly resources/tsx/pages/
            // Error.tsx page instead of Laravel's default Blade error view.
            // 422 (validation) is deliberately excluded: Inertia's useForm()
            // depends on receiving that response as-is (with its `errors`
            // payload) to populate field errors — intercepting it here would
            // break every form in the app.
            $friendlyStatuses = [400, 403, 404, 405, 419, 429, 500, 502, 503, 504];

            if (! $isApiOrJson && ! config('app.debug') && in_array($response->getStatusCode(), $friendlyStatuses, true)) {
                return Inertia::render('Error', ['status' => $response->getStatusCode()])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
