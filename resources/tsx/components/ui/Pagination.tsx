import { Link } from '@inertiajs/react';
import type { PaginationLink } from '@/types';

function cleanLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

/**
 * Laravel's `links` array is always `[ «Previous, …numbered/ellipsis…, Next» ]`.
 * On a phone the full numbered strip wraps to three lines at 360px, so
 * `< sm` collapses to just the two arrows and a "Page X / Y" marker; `sm+`
 * renders the complete windowed list exactly as before.
 */
export function Pagination({ links }: { links: PaginationLink[] }) {
    if (links.length <= 3) {
        return null;
    }

    const prev = links[0];
    const next = links[links.length - 1];
    const numbered = links.slice(1, -1);
    const current = numbered.find((link) => link.active);
    // First/last real page numbers — Laravel's elided paginator always keeps
    // both ends present, so the last numbered link is the total page count.
    const lastPage = numbered[numbered.length - 1]?.label ?? '';

    return (
        <nav className="flex items-center justify-between gap-2 py-3 sm:justify-center">
            {/* < sm: arrows + position marker only. */}
            <ArrowLink link={prev} label="« Prev" className="sm:hidden" />
            <span className="text-sm font-medium text-slate-500 sm:hidden">
                {current ? `Page ${current.label} / ${lastPage}` : ''}
            </span>
            <ArrowLink link={next} label="Next »" className="sm:hidden" />

            {/* sm+: the full windowed list, unchanged. */}
            <div className="hidden flex-wrap items-center gap-1 sm:flex">
                {links.map((link, index) => {
                    const label = cleanLabel(link.label);

                    if (!link.url) {
                        return (
                            <span key={index} className="rounded-lg px-3 py-1.5 text-sm text-slate-300">
                                {label}
                            </span>
                        );
                    }

                    return (
                        <Link
                            key={index}
                            href={link.url}
                            preserveScroll
                            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors duration-150 ${
                                link.active
                                    ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20'
                                    : 'text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            {label}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}

function ArrowLink({ link, label, className = '' }: { link: PaginationLink; label: string; className?: string }) {
    if (!link.url) {
        return (
            <span className={`rounded-lg px-3 py-2 text-sm font-medium text-slate-300 ${className}`}>{label}</span>
        );
    }

    return (
        <Link
            href={link.url}
            preserveScroll
            className={`rounded-lg px-3 py-2 text-sm font-medium text-slate-600 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-100 ${className}`}
        >
            {label}
        </Link>
    );
}
