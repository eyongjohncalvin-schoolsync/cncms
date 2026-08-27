import { IconExternalLink, IconRefresh } from '@tabler/icons-react';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';
import type { PreRunReviewResponse } from '@/types';

// Threshold for "small enough to render inline" vs. "collapse to a summary
// + link out to the full board" (task-scheduler.md's 2026-08-27 stage 3
// addendum). The inline case renders inside a max-w-md Modal (see ui/Modal)
// or a similarly narrow card — comfortably fits ~15 compact rows without
// forcing heavy internal scrolling; past that, a full Disconnections-style
// paginated board (Manuscripts/PreRunReviewList.tsx) is the better read.
const INLINE_LIST_THRESHOLD = 15;

interface PreRunReviewPanelProps {
    period: string;
    zoneUuid?: string;
    loading: boolean;
    error: string | null;
    data: PreRunReviewResponse | null;
    onReload: () => void;
}

/**
 * The "who hasn't paid yet" pre-run review — advisory context surfaced
 * alongside the manual Calculate action (Manuscripts/Index.tsx's confirm
 * modal) and again on the run-review screen once a run is `pending_review`
 * (Manuscripts/RunReview.tsx), both times fed by the same on-demand
 * GET /manuscripts/pre-run-review call (see the usePreRunReview hook). This
 * component is purely presentational — fetch state is owned by the caller
 * so both call sites share one fetch lifecycle implementation without also
 * being forced to share a mount point.
 *
 * A customer's name opens their profile via `window.open(...)`, deliberately
 * NOT Inertia's `<Link>` — mirrors Manuscripts/Index.tsx's own existing
 * WhatsApp "Send Bill" link pattern (see that page's Actions column) so
 * navigating to fix a miss happens in a new tab, leaving this panel's
 * already-fetched list (and, for the modal case, the Calculate confirmation
 * itself) alive in the original tab.
 */
export function PreRunReviewPanel({ period, zoneUuid, loading, error, data, onReload }: PreRunReviewPanelProps) {
    function openCustomer(uuid: string) {
        window.open(`/customers/${uuid}`, '_blank', 'noopener,noreferrer');
    }

    function openFullList() {
        const params = new URLSearchParams({ period });
        if (zoneUuid) {
            params.set('zone_uuid', zoneUuid);
        }
        window.open(`/manuscripts/pre-run-review/full?${params.toString()}`, '_blank', 'noopener,noreferrer');
    }

    if (loading && !data) {
        return (
            <div className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-500">
                <LoadingSpinner />
                Checking who hasn&apos;t paid yet for {period}…
            </div>
        );
    }

    if (error) {
        return (
            <div className="flex items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <span>{error}</span>
                <button type="button" onClick={onReload} className="shrink-0 font-medium underline">
                    Retry
                </button>
            </div>
        );
    }

    if (!data) {
        return null;
    }

    const { summary, customers } = data;
    const isSmall = summary.count <= INLINE_LIST_THRESHOLD;

    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <div className="flex items-start justify-between gap-2">
                <p className="text-sm text-slate-700">
                    <span className="font-semibold text-slate-900">{summary.count}</span> active customer{summary.count === 1 ? '' : 's'} flagged with
                    nothing covering period <span className="font-medium">{period}</span> yet — total exposure{' '}
                    <span className="font-semibold text-slate-900">{formatCurrency(summary.total_exposure)}</span>.
                </p>
                <button
                    type="button"
                    onClick={onReload}
                    disabled={loading}
                    className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700 disabled:opacity-50"
                >
                    <IconRefresh size={13} className={loading ? 'animate-spin' : ''} />
                    Refresh list
                </button>
            </div>

            {summary.count > 0 &&
                (isSmall ? (
                    <div className="mt-3 max-h-64 overflow-y-auto">
                        <Table>
                            <TableHead>
                                <Th className="px-2 py-1.5 text-[11px]">Name</Th>
                                <Th className="px-2 py-1.5 text-[11px]">Zone</Th>
                                <Th className="px-2 py-1.5 text-[11px]">Phone</Th>
                                <Th className="px-2 py-1.5 text-[11px]">Last Paid</Th>
                            </TableHead>
                            <TableBody>
                                {customers.map((customer) => (
                                    <tr key={customer.uuid}>
                                        <Td className="px-2 py-1.5 text-xs">
                                            <button
                                                type="button"
                                                onClick={() => openCustomer(customer.uuid)}
                                                className="font-medium text-blue-700 hover:underline"
                                            >
                                                {customer.name}
                                            </button>
                                        </Td>
                                        <Td className="px-2 py-1.5 text-xs">{customer.zone_name ?? '—'}</Td>
                                        <Td className="px-2 py-1.5 text-xs">{customer.phone ?? '—'}</Td>
                                        <Td className="px-2 py-1.5 text-xs">{customer.last_payment_date ?? 'Never'}</Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={openFullList}
                        className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700"
                    >
                        <IconExternalLink size={13} />
                        Review full list ({summary.count} customers)
                    </button>
                ))}
        </div>
    );
}
