import { ReactNode } from 'react';

export function Table({ children }: { children: ReactNode }) {
    return (
        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
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
