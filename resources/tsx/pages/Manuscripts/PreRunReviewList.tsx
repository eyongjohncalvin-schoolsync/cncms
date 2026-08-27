import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { SelectInput } from '@/components/ui/SelectInput';
import { Card } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Badge } from '@/components/ui/Badge';
import { formatCurrency } from '@/lib/formatCurrency';
import type { PaginatedResponse, PreRunReviewCustomer, PreRunReviewSummary, Zone } from '@/types';

interface ManuscriptsPreRunReviewListProps {
    period: string;
    filters: { zone_uuid: string | null };
    summary: PreRunReviewSummary;
    customers: PaginatedResponse<PreRunReviewCustomer>;
    zones: Zone[];
}

/**
 * The "large-count" companion to the pre-run review modal/panel
 * (task-scheduler.md's 2026-08-27 stage 3 addendum) — a full,
 * Disconnections/Index.tsx-shaped board (paginated table + zone filter) for
 * the same flagged-customer list, opened via "Review full list" when the
 * count is too large to render inline in a modal. Reached via `window.open`
 * from that link (Manuscripts/Index.tsx's Calculate modal, or
 * Manuscripts/RunReview.tsx), in a new tab, so the originating page's own
 * state stays alive.
 */
export default function ManuscriptsPreRunReviewList({ period, filters, summary, customers, zones }: ManuscriptsPreRunReviewListProps) {
    const [loading, setLoading] = useState(false);

    function applyZone(zoneUuid: string | undefined) {
        router.get(
            '/manuscripts/pre-run-review/full',
            { period, zone_uuid: zoneUuid },
            {
                preserveState: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    }

    function openCustomer(uuid: string) {
        // window.open, not Inertia's <Link> — mirrors Manuscripts/Index.tsx's
        // own WhatsApp "Send Bill" link pattern, so fixing a miss opens the
        // customer's profile in a new tab rather than navigating this
        // filtered/paged board away.
        window.open(`/customers/${uuid}`, '_blank', 'noopener,noreferrer');
    }

    return (
        <AppLayout title="Pre-Run Review">
            <Head title={`Pre-Run Review — ${period}`} />

            <div className="animate-fade-up mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2.5">
                        <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">Who Hasn&apos;t Paid — {period}</h1>
                        <Badge tone="red">{summary.count} flagged</Badge>
                    </div>
                    <p className="mt-1 text-sm text-slate-500">
                        Active customers with nothing covering period {period} yet — no verified payment, no active prepaid
                        window, no covering credit. Total exposure:{' '}
                        <span className="font-semibold text-slate-700">{formatCurrency(summary.total_exposure)}</span>.
                    </p>
                </div>
            </div>

            <div className="animate-fade-up mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <SelectInput
                        id="zone"
                        label="Zone"
                        value={filters.zone_uuid ?? ''}
                        onChange={(e) => applyZone(e.target.value || undefined)}
                        className="rounded-lg bg-white"
                    >
                        <option value="">All zones</option>
                        {zones.map((zone) => (
                            <option key={zone.uuid} value={zone.uuid}>
                                {zone.name}
                            </option>
                        ))}
                    </SelectInput>
                    {loading && <LoadingSpinner className="mb-2 text-slate-400" />}
                </div>
            </div>

            {customers.data.length === 0 ? (
                <EmptyState
                    title="Nobody flagged"
                    description="Every active customer in view is already covered for this period."
                />
            ) : (
                <Card className="animate-fade-up p-0">
                    <Table>
                        <TableHead>
                            <Th>Name</Th>
                            <Th>Zone</Th>
                            <Th>Phone</Th>
                            <Th>Bill</Th>
                            <Th>Last Paid</Th>
                        </TableHead>
                        <TableBody>
                            {customers.data.map((customer) => (
                                <tr key={customer.uuid} className="transition-colors hover:bg-slate-50">
                                    <Td>
                                        <button
                                            type="button"
                                            onClick={() => openCustomer(customer.uuid)}
                                            className="font-medium text-blue-700 hover:underline"
                                        >
                                            {customer.name}
                                        </button>
                                    </Td>
                                    <Td>{customer.zone_name ?? '—'}</Td>
                                    <Td>{customer.phone ?? '—'}</Td>
                                    <Td>{formatCurrency(customer.bill)}</Td>
                                    <Td>{customer.last_payment_date ?? 'Never'}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={customers.links} />
                    </div>
                </Card>
            )}
        </AppLayout>
    );
}
