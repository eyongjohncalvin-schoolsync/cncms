import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { IconReceipt2 } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card } from '@/components/ui/Card';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';
import { hasPermission } from '@/lib/permissions';
import type { Expenditure, ExpenseCategory, PageProps, PaginatedResponse } from '@/types';

interface ExpendituresIndexProps {
    expenditures: PaginatedResponse<Expenditure>;
    filters: {
        category_uuid: string | null;
        from: string | null;
        to: string | null;
    };
    categories: ExpenseCategory[];
}

export default function ExpendituresIndex({ expenditures, filters, categories }: ExpendituresIndexProps) {
    const { auth } = usePage<PageProps>().props;
    // RBAC v2 Wave 4: ExpenditurePolicy::delete → `expenditures.delete`.
    const canDelete = hasPermission(auth.user?.permissions, 'expenditures.delete');

    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', () => setIsLoading(true));
        const removeFinish = router.on('finish', () => setIsLoading(false));

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            '/resources/expenditures',
            {
                category_uuid: filters.category_uuid ?? undefined,
                from: filters.from ?? undefined,
                to: filters.to ?? undefined,
                ...next,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function destroy(expenditure: Expenditure) {
        if (!window.confirm(`Delete this ${formatCurrency(expenditure.amount)} expenditure?`)) {
            return;
        }

        router.delete(`/resources/expenditures/${expenditure.uuid}`, { preserveScroll: true });
    }

    return (
        <AppLayout
            title="Expenditures"
            breadcrumbs={[{ label: 'Resources', href: '/resources' }, { label: 'Expenditures' }]}
        >
            <Head title="Expenditures" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4 animate-fade-up">
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Expenditures</h1>
                    <p className="mt-1 text-sm text-slate-500">Every recorded expense across categories, office and field.</p>
                </div>
                <Link
                    href="/resources/expenditures/create"
                    className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition-colors hover:bg-blue-700"
                >
                    <IconReceipt2 size={18} stroke={1.75} />
                    Record Expense
                </Link>
            </div>

            <div className="mb-4 animate-fade-up rounded-lg border border-slate-200 bg-slate-50 p-4" style={{ animationDelay: '80ms' }}>
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <SelectInput
                        id="category_uuid"
                        label="Category"
                        value={filters.category_uuid ?? ''}
                        onChange={(e) => applyFilter({ category_uuid: e.target.value || undefined })}
                        className="w-full rounded-lg bg-white sm:w-auto"
                    >
                        <option value="">All categories</option>
                        {categories.map((category) => (
                            <option key={category.uuid} value={category.uuid}>
                                {category.name}
                            </option>
                        ))}
                    </SelectInput>
                    <TextInput
                        id="from"
                        label="From"
                        type="date"
                        value={filters.from ?? ''}
                        onChange={(e) => applyFilter({ from: e.target.value || undefined })}
                        className="w-full rounded-lg bg-white sm:w-auto"
                    />
                    <TextInput
                        id="to"
                        label="To"
                        type="date"
                        value={filters.to ?? ''}
                        onChange={(e) => applyFilter({ to: e.target.value || undefined })}
                        className="w-full rounded-lg bg-white sm:w-auto"
                    />
                    {isLoading && <LoadingSpinner className="mb-2 text-slate-400" />}
                </div>
            </div>

            {expenditures.data.length === 0 ? (
                <div className="animate-fade-up" style={{ animationDelay: '140ms' }}>
                    <EmptyState title="No expenditures found" description="Try a different filter or record a new expense." />
                </div>
            ) : (
                <Card className="animate-fade-up p-0" style={{ animationDelay: '140ms' }}>
                    <Table>
                        <TableHead>
                            <Th>Date</Th>
                            <Th>Category</Th>
                            <Th>Amount</Th>
                            <Th>Description</Th>
                            <Th>Recorded by</Th>
                            <Th>Source</Th>
                            {canDelete && <Th>Actions</Th>}
                        </TableHead>
                        <TableBody>
                            {expenditures.data.map((expenditure) => (
                                <tr key={expenditure.uuid} className="transition-colors hover:bg-slate-50/70">
                                    <Td>{expenditure.spent_at}</Td>
                                    <Td>{expenditure.category_name}</Td>
                                    <Td className="font-medium text-slate-900">{formatCurrency(expenditure.amount)}</Td>
                                    <Td>{expenditure.description ?? '—'}</Td>
                                    <Td>{expenditure.recorded_by_name}</Td>
                                    <Td>
                                        <Badge tone={expenditure.recorded_offline ? 'yellow' : 'slate'}>
                                            {expenditure.recorded_offline ? 'Offline' : 'Office'}
                                        </Badge>
                                    </Td>
                                    {canDelete && (
                                        <Td>
                                            <button
                                                type="button"
                                                onClick={() => destroy(expenditure)}
                                                className="text-sm font-medium text-red-600 hover:text-red-700"
                                            >
                                                Delete
                                            </button>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={expenditures.links} />
                    </div>
                </Card>
            )}
        </AppLayout>
    );
}
