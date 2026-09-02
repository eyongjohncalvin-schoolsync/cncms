import { ReactNode } from 'react';

/**
 * These register-style tables carry far more columns than a phone can show
 * (Manuscripts alone has 13). Rather than a per-column card rewrite on
 * every index page, the wrapper is a self-contained horizontal scroll
 * region: it never widens its parent (`max-w-full`), the overscroll is
 * trapped so a sideways swipe doesn't also yank the page, and it's
 * focusable + labelled so keyboard and screen-reader users can reach the
 * scroll too. The desktop table is visually unchanged.
 *
 * `label` (e.g. "Customers") gives the scroll region a meaningful accessible
 * name; pages that don't pass one still get a sensible generic label.
 */
export function Table({ children, label }: { children: ReactNode; label?: string }) {
    return (
        <div
            role="region"
            aria-label={label ? `${label} table` : 'Table — scroll sideways to see more'}
            tabIndex={0}
            className="w-full max-w-full overflow-x-auto overscroll-x-contain rounded-lg border border-slate-200 bg-white [-webkit-overflow-scrolling:touch] [scrollbar-width:thin] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
        >
            <table className="min-w-full divide-y divide-slate-100 text-sm">{children}</table>
        </div>
    );
}

export function TableHead({ children }: { children: ReactNode }) {
    return (
        <thead className="bg-slate-50/80">
            <tr className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">{children}</tr>
        </thead>
    );
}

export function TableBody({ children }: { children: ReactNode }) {
    return <tbody className="divide-y divide-slate-100 [&>tr]:transition-colors [&>tr]:duration-150 [&>tr:hover]:bg-slate-50">{children}</tbody>;
}

export function Th({ children, className = '' }: { children?: ReactNode; className?: string }) {
    return <th className={`px-4 py-3 ${className}`}>{children}</th>;
}

export function Td({ children, className = '' }: { children: ReactNode; className?: string }) {
    return <td className={`px-4 py-3 text-slate-700 ${className}`}>{children}</td>;
}
