import type { CustomerCoverage } from '@/types';

/**
 * The customer's months as the bill run plays them (CustomerCoverageService):
 * free months first, then months paid ahead, then billed — "TV: free
 * Oct–Nov 2026, paid Dec 2026–Feb 2027, billed from Mar 2027". Shown with a
 * payment so a prepayment's months are read right: they never pay for a
 * free month.
 */
export function CoverageNote({ coverage }: { coverage: CustomerCoverage | null | undefined }) {
    if (!coverage?.label) {
        return null;
    }

    return (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
            <p className="font-medium">This customer&apos;s months</p>
            <p className="mt-0.5">{coverage.label}</p>
            <p className="mt-1 text-xs text-emerald-800">Months paid ahead always start after the free months — a free month is never paid for.</p>
        </div>
    );
}
