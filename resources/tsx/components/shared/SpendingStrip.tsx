import { Link } from '@inertiajs/react';
import { formatCurrency } from '@/lib/formatCurrency';
import type { SpendingSummary } from '@/types';

const LEVEL_CLASSES = {
    ok: 'bg-slate-100 text-slate-700 ring-slate-200',
    amber: 'bg-amber-50 text-amber-800 ring-amber-300',
    red: 'bg-red-50 text-red-800 ring-red-300',
} as const;

function Figure({ label, value, hint, tone = 'slate' }: { label: string; value: string; hint?: string; tone?: 'slate' | 'red' | 'green' | 'amber' }) {
    const toneClass = { slate: 'text-slate-900', red: 'text-red-700', green: 'text-green-700', amber: 'text-amber-700' }[tone];

    return (
        <div className="min-w-0">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
            <p className={`mt-0.5 truncate text-lg font-semibold ${toneClass}`}>{value}</p>
            {hint && <p className="text-xs text-slate-500">{hint}</p>}
        </div>
    );
}

/**
 * The month's spending at a glance (docs/plans/expenditures-and-spending-controls.md,
 * items 4–7): spent, the monthly cap, budgets, income so far and the net —
 * red when spending runs ahead of income — plus a chip per category coloured
 * by its budget (amber at 80 %, red at 100 %). Warnings only; nothing here
 * blocks a bon.
 */
export function SpendingStrip({ summary, budgetsHref }: { summary: SpendingSummary; budgetsHref?: string }) {
    const capPct = summary.cap_pct ?? 0;
    const capTone = summary.cap === null ? 'slate' : capPct >= 100 ? 'red' : capPct >= 80 ? 'amber' : 'slate';

    return (
        <div className={`rounded-xl border p-4 ${summary.deficit ? 'border-red-300 bg-red-50/60' : 'border-slate-200 bg-white'}`}>
            {summary.deficit && (
                <p className="mb-3 rounded-lg bg-red-100 px-3 py-2 text-sm font-medium text-red-800">
                    Spending is above this month&apos;s verified income — {formatCurrency(summary.net.replace('-', ''))} short. Hold back non-essential bons.
                </p>
            )}
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                <Figure label="Spent" value={formatCurrency(summary.spent)} hint={summary.committed !== summary.spent ? `${formatCurrency(summary.committed)} committed` : undefined} />
                <Figure
                    label="Monthly cap"
                    value={summary.cap ? formatCurrency(summary.cap) : 'No cap'}
                    hint={summary.cap ? `${capPct}% used` : undefined}
                    tone={capTone}
                />
                <Figure label="Budgeted" value={formatCurrency(summary.budget_total)} hint={summary.budget_warnings > 0 ? `${summary.budget_warnings} categor${summary.budget_warnings === 1 ? 'y' : 'ies'} near or over` : undefined} tone={summary.budget_warnings > 0 ? 'amber' : 'slate'} />
                <Figure label="Income so far" value={formatCurrency(summary.income)} hint="Verified, excl. technician fees" />
                <Figure label="Net" value={formatCurrency(summary.net)} tone={summary.deficit ? 'red' : 'green'} />
            </div>
            {summary.categories.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {summary.categories.map((category) => (
                        <span key={category.uuid} className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${LEVEL_CLASSES[category.level]}`}>
                            {category.name} · {formatCurrency(category.amount)}
                            {category.budget !== null && <span className="opacity-75">/ {formatCurrency(category.budget)}</span>}
                        </span>
                    ))}
                    {budgetsHref && (
                        <Link href={budgetsHref} className="text-xs font-medium text-blue-700 hover:underline">
                            Budgets →
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}
