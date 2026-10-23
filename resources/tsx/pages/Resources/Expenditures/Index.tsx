import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { IconDownload, IconLock, IconReceipt2 } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card } from '@/components/ui/Card';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { SpendingStrip } from '@/components/shared/SpendingStrip';
import { formatCurrency } from '@/lib/formatCurrency';
import { SOURCE_LABELS, monthLabel } from '@/lib/expenditures';
import type { Expenditure, ExpenditureSource, PaginatedResponse, SpendingSummary } from '@/types';

interface MonthStatus {
    closed: boolean;
    closed_at: string | null;
    closed_by_name: string | null;
    reopened_at: string | null;
    reopened_by_name: string | null;
    reopen_reason: string | null;
    can_close: boolean;
}

interface Filters {
    month: string;
    category_uuid: string | null;
    source: ExpenditureSource | null;
    status: 'active' | 'void' | null;
    user_uuid: string | null;
}

interface ExpendituresIndexProps {
    month: string;
    current_month: string;
    month_status: MonthStatus;
    months: { month: string; closed: boolean }[];
    summary: SpendingSummary;
    expenditures: PaginatedResponse<Expenditure>;
    filters: Filters;
    categories: { uuid: string; name: string; is_active: boolean }[];
    recorders: { uuid: string; name: string }[];
    can: { manage_months: boolean; manage_budgets: boolean };
}

/**
 * Expenditures — one month at a time (docs/plans/expenditures-and-spending-controls.md,
 * item 7). The current Cameroon month by default; earlier months are an
 * archive, read-only once closed on the 5th. Nothing is recorded here: every
 * cash-out is a bon (E1), so the action is "Request a bon".
 */
export default function ExpendituresIndex(props: ExpendituresIndexProps) {
    const { month, current_month, month_status, months, summary, expenditures, filters, categories, recorders, can } = props;
    const [isLoading, setIsLoading] = useState(false);
    const [reopenOpen, setReopenOpen] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', () => setIsLoading(true));
        const removeFinish = router.on('finish', () => setIsLoading(false));

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    const query = (next: Partial<Filters>): Record<string, string> => {
        const merged = { ...filters, ...next };

        return Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== null && value !== undefined && value !== '')) as Record<string, string>;
    };

    function applyFilter(next: Partial<Filters>) {
        router.get('/resources/expenditures', query(next), { preserveState: true, preserveScroll: true, replace: true });
    }

    const exportHref = `/resources/expenditures/export?${new URLSearchParams(query({})).toString()}`;

    return (
        <AppLayout title="Expenditures" breadcrumbs={[{ label: 'Resources', href: '/resources' }, { label: 'Expenditures' }]}>
            <Head title="Expenditures" />

            <div className="mb-4 flex flex-wrap items-start justify-between gap-4 animate-fade-up">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="font-display text-2xl text-slate-900">Expenditures — {monthLabel(month)}</h1>
                        {month_status.closed && (
                            <Badge tone="slate">
                                <IconLock size={12} className="mr-1 inline" /> Closed
                            </Badge>
                        )}
                        {month !== current_month && !month_status.closed && <Badge tone="yellow">Open</Badge>}
                    </div>
                    <p className="mt-1 text-sm text-slate-500">
                        Every franc out goes through a bon. {month_status.closed
                            ? `Closed ${month_status.closed_at ? new Date(month_status.closed_at).toLocaleDateString() : ''} by ${month_status.closed_by_name ?? '—'} — corrections go in the current month.`
                            : month_status.reopened_at
                              ? `Reopened by ${month_status.reopened_by_name ?? '—'}: ${month_status.reopen_reason ?? ''}`
                              : ''}
                    </p>
                </div>
                <div className="flex flex-wrap items-end gap-2">
                    <SelectInput id="month" label="Month" value={month} onChange={(e) => applyFilter({ month: e.target.value })} className="rounded-lg bg-white">
                        {months.map((option) => (
                            <option key={option.month} value={option.month}>
                                {monthLabel(option.month)}
                                {option.closed ? ' (closed)' : ''}
                            </option>
                        ))}
                    </SelectInput>
                    <a href={exportHref} className="inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">
                        <IconDownload size={16} stroke={1.75} /> Export
                    </a>
                    {can.manage_budgets && (
                        <Link href={`/resources/budgets?month=${month}`} className="inline-flex items-center rounded-lg px-3.5 py-2 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">
                            Budgets
                        </Link>
                    )}
                    {can.manage_months && month_status.can_close && (
                        <Button
                            variant="secondary"
                            onClick={() => window.confirm(`Close ${monthLabel(month)}? Its expenditures become read-only.`) && router.post(`/resources/expenditure-months/${month}/close`, {}, { preserveScroll: true })}
                        >
                            Close month
                        </Button>
                    )}
                    {can.manage_months && month_status.closed && (
                        <Button variant="warning" onClick={() => setReopenOpen(true)}>
                            Reopen month
                        </Button>
                    )}
                    <Link
                        href="/vouchers"
                        className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-700"
                    >
                        <IconReceipt2 size={18} stroke={1.75} /> Request a bon
                    </Link>
                </div>
            </div>

            <div className="mb-4 animate-fade-up">
                <SpendingStrip summary={summary} budgetsHref={can.manage_budgets ? `/resources/budgets?month=${month}` : undefined} />
            </div>

            <div className="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <SelectInput id="category_uuid" label="Category" value={filters.category_uuid ?? ''} onChange={(e) => applyFilter({ category_uuid: e.target.value || null })} className="rounded-lg bg-white">
                        <option value="">All categories</option>
                        {categories.map((category) => (
                            <option key={category.uuid} value={category.uuid}>
                                {category.name}
                                {category.is_active ? '' : ' (inactive)'}
                            </option>
                        ))}
                    </SelectInput>
                    <SelectInput id="source" label="Source" value={filters.source ?? ''} onChange={(e) => applyFilter({ source: (e.target.value || null) as Filters['source'] })} className="rounded-lg bg-white">
                        <option value="">All sources</option>
                        <option value="voucher">Bons</option>
                        <option value="adjustment">Corrections</option>
                        <option value="legacy">Legacy (recorded directly)</option>
                    </SelectInput>
                    <SelectInput id="status" label="Status" value={filters.status ?? ''} onChange={(e) => applyFilter({ status: (e.target.value || null) as Filters['status'] })} className="rounded-lg bg-white">
                        <option value="">Counted and void</option>
                        <option value="active">Counted</option>
                        <option value="void">Void</option>
                    </SelectInput>
                    <SelectInput id="user_uuid" label="Recorded by" value={filters.user_uuid ?? ''} onChange={(e) => applyFilter({ user_uuid: e.target.value || null })} className="rounded-lg bg-white">
                        <option value="">Anyone</option>
                        {recorders.map((recorder) => (
                            <option key={recorder.uuid} value={recorder.uuid}>
                                {recorder.name}
                            </option>
                        ))}
                    </SelectInput>
                    {isLoading && <LoadingSpinner className="mb-2 text-slate-400" />}
                </div>
            </div>

            {expenditures.data.length === 0 ? (
                <EmptyState title="Nothing spent in this view" description="Expenditures appear here when a bon is paid or settled." />
            ) : (
                <Card className="p-0">
                    <Table label="Expenditures">
                        <TableHead>
                            <Th>Date</Th>
                            <Th>Bon</Th>
                            <Th>Category</Th>
                            <Th>Description</Th>
                            <Th>Recorded by</Th>
                            <Th>Source</Th>
                            <Th className="text-right">Amount</Th>
                        </TableHead>
                        <TableBody>
                            {expenditures.data.map((expenditure) => (
                                <tr key={expenditure.uuid} className={`transition-colors hover:bg-slate-50/70 ${expenditure.status === 'void' ? 'text-slate-400 line-through' : ''}`}>
                                    <Td>
                                        <Link href={`/resources/expenditures/${expenditure.uuid}`} className="font-medium text-blue-700 hover:underline">
                                            {expenditure.spent_at}
                                        </Link>
                                    </Td>
                                    <Td>
                                        {expenditure.voucher ? (
                                            <Link href={`/vouchers/${expenditure.voucher.uuid}`} className="text-blue-700 hover:underline">
                                                {expenditure.voucher.number}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </Td>
                                    <Td>{expenditure.category_name ?? '—'}</Td>
                                    <Td>
                                        <div className="max-w-xs truncate">{expenditure.description ?? '—'}</div>
                                        {expenditure.relates_to_period && <div className="text-xs text-slate-500">Relates to {monthLabel(expenditure.relates_to_period)}</div>}
                                    </Td>
                                    <Td>{expenditure.recorded_by_name ?? '—'}</Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            <Badge tone={expenditure.source === 'voucher' ? 'blue' : expenditure.source === 'adjustment' ? 'green' : 'slate'}>{SOURCE_LABELS[expenditure.source]}</Badge>
                                            {expenditure.status === 'void' && <Badge tone="red">Void</Badge>}
                                        </div>
                                    </Td>
                                    <Td className="text-right font-medium">{formatCurrency(expenditure.amount)}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={expenditures.links} />
                    </div>
                </Card>
            )}

            <ReopenModal open={reopenOpen} onClose={() => setReopenOpen(false)} month={month} />
        </AppLayout>
    );
}

function ReopenModal({ open, onClose, month }: { open: boolean; onClose: () => void; month: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    return (
        <Modal open={open} onClose={onClose} title={`Reopen ${monthLabel(month)}`}>
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/resources/expenditure-months/${month}/reopen`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            reset();
                            onClose();
                        },
                    });
                }}
            >
                <p className="text-sm text-slate-600">Reopening is recorded and everyone who sees the P&amp;L is told. Close the month again once the fix is in.</p>
                <TextInput id="reason" label="Why reopen it?" value={data.reason} onChange={(e) => setData('reason', e.target.value)} error={errors.reason} required />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="warning" disabled={processing}>
                        Reopen
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
