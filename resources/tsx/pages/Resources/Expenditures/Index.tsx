import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { IconChecklist, IconDownload, IconFilter, IconPaperclip, IconReceipt2 } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card } from '@/components/ui/Card';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ResourcesTabs } from '@/components/resources/ResourcesTabs';
import { ExpenseStatusBadge, FlagChips, formatDate, paymentMethodLabel } from '@/components/resources/ExpenseUi';
import { formatCurrency } from '@/lib/formatCurrency';
import { hasPermission } from '@/lib/permissions';
import type { Expenditure, NamedRef, PageProps, PaginatedResponse } from '@/types';

interface ExpenditureFilters {
    category_uuid: string | null;
    from: string | null;
    to: string | null;
    status: string | null;
    supplier_uuid: string | null;
    service_uuid: string | null;
}

interface ExpendituresIndexProps {
    expenditures: PaginatedResponse<Expenditure>;
    filters: ExpenditureFilters;
    status_counts: Record<string, number>;
    categories: NamedRef[];
    suppliers: NamedRef[];
    services: NamedRef[];
}

/** Status tabs; `pending` covers every "waiting" status on the server. */
const STATUS_TABS: { value: string | null; label: string; statuses: string[] | null }[] = [
    { value: null, label: 'All', statuses: null },
    { value: 'pending', label: 'Waiting', statuses: ['pending_evidence', 'pending_approval', 'pending_second_approval'] },
    { value: 'approved', label: 'Approved', statuses: ['approved'] },
    { value: 'rejected', label: 'Rejected', statuses: ['rejected'] },
    { value: 'void', label: 'Void', statuses: ['void'] },
];

export default function ExpendituresIndex({ expenditures, filters, status_counts, categories, suppliers, services }: ExpendituresIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const permissions = auth.user?.permissions;
    const canCreate = hasPermission(permissions, 'expenditures.create');
    const canExport = hasPermission(permissions, 'expenditures.dashboard');

    const [isLoading, setIsLoading] = useState(false);
    const hasExtraFilters = Boolean(filters.category_uuid || filters.from || filters.to || filters.supplier_uuid || filters.service_uuid);
    const [showFilters, setShowFilters] = useState(hasExtraFilters);

    useEffect(() => {
        const removeStart = router.on('start', () => setIsLoading(true));
        const removeFinish = router.on('finish', () => setIsLoading(false));

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    function applyFilter(next: Partial<ExpenditureFilters>) {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== null && value !== ''));

        router.get('/resources/expenditures', query, { preserveState: true, preserveScroll: true, replace: true });
    }

    function countFor(statuses: string[] | null): number {
        const entries = Object.entries(status_counts ?? {});

        return entries
            .filter(([status]) => statuses === null || statuses.includes(status))
            .reduce((sum, [, count]) => sum + Number(count), 0);
    }

    const activeTab = filters.status?.startsWith('pending') ? 'pending' : (filters.status ?? null);

    const exportQuery = new URLSearchParams();
    if (filters.from) {
        exportQuery.set('from', filters.from);
    }
    if (filters.to) {
        exportQuery.set('to', filters.to);
    }
    const exportHref = `/resources/expenditures/export${exportQuery.toString() ? `?${exportQuery.toString()}` : ''}`;

    return (
        <AppLayout title="Expenses" breadcrumbs={[{ label: 'Resources', href: '/resources' }, { label: 'Expenses' }]}>
            <Head title="Expenses" />

            <ResourcesTabs active="expenses" />

            <div className="mb-5 flex flex-col gap-4 animate-fade-up sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Expenses</h1>
                    <p className="mt-1 text-sm text-slate-500">Every expense recorded, with its approval status. Nothing is ever deleted.</p>
                </div>
                <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-end">
                    {canExport && (
                        <a
                            href={exportHref}
                            className="inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50 sm:min-h-0"
                        >
                            <IconDownload size={18} stroke={1.75} />
                            Download Excel
                        </a>
                    )}
                    <Link
                        href="/resources/approvals"
                        className="inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50 sm:min-h-0"
                    >
                        <IconChecklist size={18} stroke={1.75} />
                        Approvals
                    </Link>
                    {canCreate && (
                        <Link
                            href="/resources/expenditures/create"
                            className="col-span-2 inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition-colors hover:bg-blue-700 sm:min-h-0"
                        >
                            <IconReceipt2 size={18} stroke={1.75} />
                            Record expense
                        </Link>
                    )}
                </div>
            </div>

            {/* Status tabs */}
            <div
                className="-mx-4 mb-3 flex gap-2 overflow-x-auto px-4 pb-1 animate-fade-up [scrollbar-width:none] sm:mx-0 sm:px-0"
                style={{ animationDelay: '60ms' }}
                role="tablist"
                aria-label="Filter by status"
            >
                {STATUS_TABS.map((tab) => {
                    const isActive = activeTab === tab.value;

                    return (
                        <button
                            key={tab.label}
                            type="button"
                            role="tab"
                            aria-selected={isActive}
                            onClick={() => applyFilter({ status: tab.value })}
                            className={`inline-flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition-colors ${
                                isActive ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20' : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50'
                            }`}
                        >
                            {tab.label}
                            <span
                                className={`rounded-full px-1.5 text-xs font-semibold ${
                                    isActive ? 'bg-white/20 text-white' : tab.value === 'pending' && countFor(tab.statuses) > 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-500'
                                }`}
                            >
                                {countFor(tab.statuses)}
                            </span>
                        </button>
                    );
                })}
                <button
                    type="button"
                    onClick={() => setShowFilters((value) => !value)}
                    aria-expanded={showFilters}
                    className={`ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium ring-1 ring-inset transition-colors ${
                        hasExtraFilters ? 'bg-blue-50 text-blue-700 ring-blue-200' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
                    }`}
                >
                    <IconFilter size={16} stroke={1.75} />
                    Filters
                </button>
            </div>

            {showFilters && (
                <div className="mb-4 animate-fade-up rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <SelectInput
                            id="category_uuid"
                            label="Category"
                            value={filters.category_uuid ?? ''}
                            onChange={(e) => applyFilter({ category_uuid: e.target.value || null })}
                        >
                            <option value="">All categories</option>
                            {categories.map((category) => (
                                <option key={category.uuid} value={category.uuid}>
                                    {category.name}
                                </option>
                            ))}
                        </SelectInput>
                        <SelectInput
                            id="supplier_uuid"
                            label="Supplier"
                            value={filters.supplier_uuid ?? ''}
                            onChange={(e) => applyFilter({ supplier_uuid: e.target.value || null })}
                        >
                            <option value="">All suppliers</option>
                            {suppliers.map((supplier) => (
                                <option key={supplier.uuid} value={supplier.uuid}>
                                    {supplier.name}
                                </option>
                            ))}
                        </SelectInput>
                        <SelectInput
                            id="service_uuid"
                            label="Service"
                            value={filters.service_uuid ?? ''}
                            onChange={(e) => applyFilter({ service_uuid: e.target.value || null })}
                        >
                            <option value="">All services</option>
                            {services.map((service) => (
                                <option key={service.uuid} value={service.uuid}>
                                    {service.name}
                                </option>
                            ))}
                        </SelectInput>
                        <TextInput
                            id="from"
                            label="From"
                            type="date"
                            value={filters.from ?? ''}
                            onChange={(e) => applyFilter({ from: e.target.value || null })}
                        />
                        <TextInput
                            id="to"
                            label="To"
                            type="date"
                            value={filters.to ?? ''}
                            onChange={(e) => applyFilter({ to: e.target.value || null })}
                        />
                    </div>
                    {hasExtraFilters && (
                        <button
                            type="button"
                            onClick={() => applyFilter({ category_uuid: null, supplier_uuid: null, service_uuid: null, from: null, to: null })}
                            className="mt-3 text-sm font-medium text-blue-700 hover:text-blue-800"
                        >
                            Clear filters
                        </button>
                    )}
                </div>
            )}

            {isLoading && (
                <div className="mb-2 flex items-center gap-2 text-sm text-slate-400">
                    <LoadingSpinner className="text-slate-400" /> Loading…
                </div>
            )}

            {expenditures.data.length === 0 ? (
                <div className="animate-fade-up" style={{ animationDelay: '120ms' }}>
                    <EmptyState title="No expenses found" description="Try a different filter or record a new expense." />
                </div>
            ) : (
                <div className="animate-fade-up" style={{ animationDelay: '120ms' }}>
                    {/* Phones: one tappable card per expense. */}
                    <ul className="flex flex-col gap-2 sm:hidden">
                        {expenditures.data.map((expenditure) => (
                            <li key={expenditure.uuid}>
                                <ExpenseCard expenditure={expenditure} />
                            </li>
                        ))}
                    </ul>

                    {/* Larger screens: the register table. */}
                    <Card className="hidden p-0 sm:block">
                        <Table label="Expenses">
                            <TableHead>
                                <Th>Date</Th>
                                <Th>Category</Th>
                                <Th>Description</Th>
                                <Th>Supplier</Th>
                                <Th className="text-right">Amount</Th>
                                <Th>Paid by</Th>
                                <Th>Status</Th>
                                <Th>Flags</Th>
                                <Th>
                                    <span className="sr-only">Receipt</span>
                                </Th>
                            </TableHead>
                            <TableBody>
                                {expenditures.data.map((expenditure) => {
                                    const isVoid = expenditure.status === 'void';
                                    const href = `/resources/expenditures/${expenditure.uuid}`;

                                    return (
                                        <tr
                                            key={expenditure.uuid}
                                            onClick={() => router.visit(href)}
                                            className={`cursor-pointer ${isVoid ? 'text-slate-400 line-through decoration-slate-400' : ''}`}
                                        >
                                            <Td className="whitespace-nowrap">{formatDate(expenditure.spent_at)}</Td>
                                            <Td className="whitespace-nowrap">{expenditure.category_name}</Td>
                                            <Td className="max-w-[16rem]">
                                                <Link
                                                    href={href}
                                                    onClick={(e) => e.stopPropagation()}
                                                    className="line-clamp-2 font-medium text-slate-900 hover:text-blue-700"
                                                >
                                                    {expenditure.description || <span className="text-slate-400">No description</span>}
                                                </Link>
                                            </Td>
                                            <Td className="whitespace-nowrap">{expenditure.supplier?.name ?? '—'}</Td>
                                            <Td className="text-right font-semibold whitespace-nowrap text-slate-900 tabular-nums">
                                                {formatCurrency(expenditure.amount)}
                                            </Td>
                                            <Td className="whitespace-nowrap">{paymentMethodLabel(expenditure.payment_method)}</Td>
                                            <Td>
                                                <ExpenseStatusBadge status={expenditure.status} />
                                            </Td>
                                            <Td>
                                                <FlagChips flags={expenditure.flags} max={2} />
                                            </Td>
                                            <Td>
                                                {expenditure.has_receipt ? (
                                                    <IconPaperclip size={18} stroke={1.75} className="text-slate-500" aria-label="Has receipt" />
                                                ) : null}
                                            </Td>
                                        </tr>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </Card>

                    <div className="px-1">
                        <Pagination links={expenditures.links} />
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

function ExpenseCard({ expenditure }: { expenditure: Expenditure }) {
    const isVoid = expenditure.status === 'void';

    return (
        <Link
            href={`/resources/expenditures/${expenditure.uuid}`}
            className="block rounded-lg border border-slate-200 bg-white p-3.5 shadow-sm shadow-slate-900/5 transition-colors active:bg-slate-50"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className={`truncate font-medium text-slate-900 ${isVoid ? 'text-slate-400 line-through' : ''}`}>
                        {expenditure.description || expenditure.category_name}
                    </p>
                    <p className="mt-0.5 truncate text-xs text-slate-500">
                        {formatDate(expenditure.spent_at)} · {expenditure.category_name}
                        {expenditure.supplier ? ` · ${expenditure.supplier.name}` : ''}
                    </p>
                </div>
                <p className={`shrink-0 font-semibold tabular-nums ${isVoid ? 'text-slate-400 line-through' : 'text-slate-900'}`}>
                    {formatCurrency(expenditure.amount)}
                </p>
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                <ExpenseStatusBadge status={expenditure.status} />
                {expenditure.payment_method && <span className="text-xs text-slate-500">{paymentMethodLabel(expenditure.payment_method)}</span>}
                {expenditure.has_receipt && <IconPaperclip size={15} stroke={1.75} className="text-slate-400" aria-label="Has receipt" />}
            </div>
            {expenditure.flags.length > 0 && (
                <div className="mt-2">
                    <FlagChips flags={expenditure.flags} max={2} />
                </div>
            )}
        </Link>
    );
}
