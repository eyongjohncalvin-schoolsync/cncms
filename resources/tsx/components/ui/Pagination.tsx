import { Link } from '@inertiajs/react';
import type { PaginationLink } from '@/types';

export function Pagination({ links }: { links: PaginationLink[] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center gap-1 py-3">
            {links.map((link, index) => {
                const label = link.label.replace('&laquo;', '«').replace('&raquo;', '»');

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
        </nav>
    );
}
