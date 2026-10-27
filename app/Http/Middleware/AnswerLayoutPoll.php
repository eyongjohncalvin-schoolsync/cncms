<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

/**
 * AppLayout refreshes the bell and the maintenance banner every minute with
 * an Inertia partial reload of the CURRENT page (`usePoll`, only:
 * notifications, maintenance). Inertia only skips props that are closures,
 * so every such poll ran the whole page controller — a dashboard's ten
 * queries, the cash page's full ledger — per open tab, per minute, to throw
 * the result away (query audit, 2026-10-27).
 *
 * Both props are shared (HandleInertiaRequests::share()), so a poll asking
 * for nothing else is answered here with the page component and no page
 * props: the client merges the two fresh shared props into the page it
 * already has. Registered after the account-active check, so a deactivated
 * user's poll is still refused.
 */
class AnswerLayoutPoll
{
    /** Shared props a poll may ask for and get without the page controller. */
    private const array LAYOUT_PROPS = ['notifications', 'maintenance'];

    public function handle(Request $request, Closure $next): Response
    {
        $component = $request->header(Header::PARTIAL_COMPONENT);
        $only = array_filter(explode(',', (string) $request->header(Header::PARTIAL_ONLY)));

        if ($request->isMethod('GET') && $request->header(Header::INERTIA) && $component
            && $only !== [] && array_diff($only, self::LAYOUT_PROPS) === []
            && ! $request->hasHeader(Header::PARTIAL_EXCEPT)) {
            return Inertia::render($component)->toResponse($request);
        }

        return $next($request);
    }
}
