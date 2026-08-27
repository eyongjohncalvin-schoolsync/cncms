/**
 * Reads Laravel's `XSRF-TOKEN` cookie (set by the VerifyCsrfToken/
 * PreventRequestForgery middleware for every session, non-HttpOnly by
 * design) and returns it as the `X-XSRF-TOKEN` header Laravel expects on a
 * plain fetch() POST — the same convention Laravel's own SPA docs
 * recommend. Only needed for POST/PATCH/DELETE requests made via a manual
 * fetch() call outside Inertia's router (which already attaches this
 * automatically) — see Customers/Index.tsx's bulk bill-update preview call,
 * which mirrors Payments/Create.tsx's existing "plain fetch(), not
 * router.visit()" pattern for a non-navigating background request but adds
 * this header since that precedent's request was a GET (CSRF-exempt) and
 * this one is a POST.
 */
export function xsrfTokenHeader(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}
