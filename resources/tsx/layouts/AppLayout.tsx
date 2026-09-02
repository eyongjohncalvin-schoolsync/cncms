import { Link, router, usePage, usePoll } from '@inertiajs/react';
import { ReactNode, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    IconLogout,
    IconBuildingSkyscraper,
    IconMenu2,
} from '@tabler/icons-react';
import type { BreadcrumbItem, PageProps } from '@/types';
import { RoleBadge } from '@/components/shared/StatusBadge';
import { LanguageSwitcher } from '@/components/shared/LanguageSwitcher';
import { NotificationBell } from '@/components/shared/NotificationBell';
import { EmergencyBanner } from '@/components/shared/EmergencyBanner';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { AppNav } from '@/components/shared/AppNav';
import { MobileNavDrawer } from '@/layouts/MobileNavDrawer';

// The nav item list, per-item accents, role-gating and the logo-chip block
// live in components/shared/AppNav.tsx — shared verbatim by the desktop
// <aside> below and the mobile off-canvas drawer so the two never fork.

const MOBILE_NAV_ID = 'app-mobile-nav';

/** First letters of the first two words, e.g. "Ebai Kelvin" -> "EK". */
function initials(name: string): string {
    return (
        name
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map((w) => w[0] ?? '')
            .join('')
            .toUpperCase() || '?'
    );
}

export function AppLayout({
    title,
    children,
    breadcrumbs,
}: {
    title: string;
    children: ReactNode;
    /**
     * Optional trail rendered between the header and page content (e.g.
     * "Home / Customers / John Doe"). Omit entirely on pages that haven't
     * been wired up yet — nothing renders and layout is unaffected.
     */
    breadcrumbs?: BreadcrumbItem[];
}) {
    const { auth, flash, notifications, company } = usePage<PageProps>().props;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';
    const role = auth.user?.role ?? null;
    const { t } = useTranslation();

    // Keeps the bell/badge and emergency banner fresh without a real-time
    // push channel (in-app-notifications.md section 1 — polling is the
    // deliberate v1 choice here, not a placeholder for something better).
    // `only: ['notifications']` keeps each tick a cheap partial reload
    // rather than re-fetching the whole page's props. No-ops harmlessly
    // when there's no authenticated user (notifications stays null either
    // way). 60s is a deliberate perf choice — a cable operator's in-app
    // notices aren't time-critical enough to warrant a tighter interval.
    usePoll(60000, { only: ['notifications'] });

    // Mobile off-canvas nav (`< md`). Desktop (`>= md`) never opens this —
    // the <aside> below is the only nav there and the hamburger is
    // `md:hidden`. Closed on every Inertia navigation so a tapped link
    // doesn't leave the drawer covering the page it just loaded; Escape /
    // backdrop-tap / focus-trap / scroll-lock are handled by Headless UI's
    // Dialog inside MobileNavDrawer.
    const [drawerOpen, setDrawerOpen] = useState(false);
    useEffect(() => router.on('navigate', () => setDrawerOpen(false)), []);

    // Drives the header's "floating" shadow/border — flat while at the top
    // of the page, a subtle elevated shadow once content has scrolled
    // beneath it, rather than a shadow that's always present. Guarded for
    // SSR (no `window` at render time) the same way `currentPath` above is.
    const [scrolled, setScrolled] = useState(false);
    useEffect(() => {
        function onScroll() {
            setScrolled(window.scrollY > 4);
        }
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);
    return (
        <div className="flex min-h-screen bg-slate-50">
            {/* Sidebar — light and airy, on the same blue-slate family as
                the auth pages: a soft white → slate → blue-50 gradient with
                a faint blue glow, a hairline right edge, the auth logo chip,
                and smooth soft-blue nav states. Each item keeps its own
                accent-tinted icon at rest so the list isn't monochrome; the
                active state is a gentle blue pill. Hidden `< md`, where the
                MobileNavDrawer (opened by the header hamburger) takes over
                with the exact same <AppNav> content. */}
            <aside className="sticky top-0 hidden h-screen w-56 shrink-0 flex-col overflow-y-auto border-r border-slate-200/70 bg-slate-50 md:flex">
                <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 h-screen">
                    <div className="absolute inset-0 bg-gradient-to-b from-white via-slate-50 to-blue-50/50" />
                    <div className="absolute -top-16 left-1/2 h-52 w-52 -translate-x-1/2 rounded-full bg-blue-200/25 blur-3xl" />
                </div>

                <AppNav role={role} currentPath={currentPath} companyName={company?.name} />
            </aside>

            <MobileNavDrawer
                open={drawerOpen}
                onClose={() => setDrawerOpen(false)}
                id={MOBILE_NAV_ID}
                role={role}
                currentPath={currentPath}
                companyName={company?.name}
            />

            <div className="flex min-w-0 flex-1 flex-col">
                <header
                    className={`sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b px-4 backdrop-blur-md transition-all duration-200 md:px-6 ${
                        scrolled
                            ? 'border-slate-200/80 bg-white/85 shadow-sm shadow-slate-900/[0.04]'
                            : 'border-slate-200/60 bg-white/70'
                    }`}
                >
                    {/* Hairline blue accent tying the header to the sidebar's
                        blue-slate palette (matches the auth pages). */}
                    <span
                        aria-hidden="true"
                        className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-blue-500/70 via-blue-400/30 to-transparent"
                    />

                    <div className="flex min-w-0 items-center gap-1.5">
                        {/* Hamburger — opens the off-canvas nav on phones.
                            `md:hidden` so it never shows once the sticky
                            <aside> is visible. Focus returns here on close
                            (Headless UI Dialog restores it to the trigger). */}
                        {auth.user && (
                            <button
                                type="button"
                                onClick={() => setDrawerOpen(true)}
                                aria-label={t('common.open_menu')}
                                aria-expanded={drawerOpen}
                                aria-controls={MOBILE_NAV_ID}
                                className="-ml-1.5 shrink-0 rounded-lg p-2 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 md:hidden"
                            >
                                <IconMenu2 size={20} stroke={1.75} />
                            </button>
                        )}
                        <h1 className="font-display truncate text-xl leading-none tracking-tight text-slate-900">
                            {title}
                        </h1>
                    </div>

                    <div className="flex shrink-0 items-center gap-1">
                        {auth.user && (
                            <>
                                {auth.user.is_landlord && (
                                    <Link
                                        href="/landlord/tenants"
                                        className="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-indigo-600 transition-colors hover:bg-indigo-50 hover:text-indigo-700"
                                        title={t('common.landlord')}
                                    >
                                        <IconBuildingSkyscraper size={16} stroke={1.75} />
                                        <span className="hidden lg:inline">{t('common.landlord')}</span>
                                    </Link>
                                )}

                                <div className="flex items-center gap-0.5 text-slate-500 [&_button]:rounded-lg [&_button:hover]:bg-slate-100">
                                    <NotificationBell />
                                    <LanguageSwitcher />
                                </div>

                                <span aria-hidden="true" className="mx-1.5 hidden h-6 w-px bg-slate-200 sm:block" />

                                <div className="flex items-center gap-2 rounded-full py-1 pr-1 pl-1 transition-colors hover:bg-slate-100/70">
                                    <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-blue-700 text-[11px] font-semibold text-white shadow-sm shadow-blue-600/20 ring-1 ring-white/40">
                                        {initials(auth.user.name)}
                                    </span>
                                    <span className="hidden text-sm font-medium text-slate-700 sm:inline">
                                        {auth.user.name}
                                    </span>
                                    {/* Role badge is noise on a phone where the row
                                        is already tight — the name is hidden `< sm`
                                        too, so hide the badge on the same breakpoint. */}
                                    <span className="hidden sm:inline-flex">
                                        <RoleBadge role={auth.user.role} />
                                    </span>
                                </div>

                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    className="rounded-lg p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
                                    title={t('common.log_out')}
                                >
                                    <IconLogout size={18} stroke={1.75} />
                                </Link>
                            </>
                        )}
                    </div>
                </header>

                {breadcrumbs && breadcrumbs.length > 0 && (
                    <div className="border-b border-slate-200 bg-slate-50/70 px-4 py-2 md:px-6">
                        <Breadcrumb items={breadcrumbs} />
                    </div>
                )}

                <EmergencyBanner notifications={notifications?.emergency ?? []} />

                <main className="flex-1 p-4 md:p-6">
                    {flash.success && (
                        <div className="mb-4 rounded-md border-l-4 border-green-500 bg-green-100 px-4 py-3 text-sm text-green-800">
                            {flash.success}
                        </div>
                    )}
                    {flash.error && (
                        <div className="mb-4 rounded-md border-l-4 border-red-500 bg-red-100 px-4 py-3 text-sm text-red-800">
                            {flash.error}
                        </div>
                    )}
                    {children}
                </main>

                <footer className="border-t border-slate-200 bg-white px-4 py-3 text-center text-xs text-slate-400 md:px-6">
                    {t('common.footer_copyright', { year: new Date().getFullYear() })}
                </footer>
            </div>
        </div>
    );
}
