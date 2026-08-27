import { Link } from '@inertiajs/react';
import { IconChevronRight } from '@tabler/icons-react';
import type { BreadcrumbItem } from '@/types';

/**
 * Compact breadcrumb trail rendered by AppLayout between the header and
 * page content (e.g. "Home / Customers / John Doe"). All but the last item
 * render as links in muted slate; the last item is treated as the current
 * page — plain text, slightly heavier weight, not a link even if it
 * happens to carry an `href`. See `BreadcrumbItem` in `@/types` for the
 * exact contract every page composes this from.
 */
export function Breadcrumb({ items }: { items: BreadcrumbItem[] }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <nav aria-label="Breadcrumb" className="flex items-center">
            <ol className="flex flex-wrap items-center gap-1 text-sm">
                {items.map((item, index) => {
                    const isLast = index === items.length - 1;

                    return (
                        <li key={`${item.label}-${index}`} className="flex items-center gap-1">
                            {index > 0 && <IconChevronRight size={14} stroke={2} className="mx-0.5 shrink-0 text-slate-400" aria-hidden="true" />}
                            {!isLast && item.href ? (
                                <Link
                                    href={item.href}
                                    className="rounded px-1 py-0.5 text-slate-500 transition-colors hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                                >
                                    {item.label}
                                </Link>
                            ) : (
                                <span className={isLast ? 'px-1 py-0.5 font-medium text-slate-900' : 'px-1 py-0.5 text-slate-500'} aria-current={isLast ? 'page' : undefined}>
                                    {item.label}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
