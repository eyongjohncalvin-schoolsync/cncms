import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Customer, CustomerStatus, PaginatedResponse, Zone } from '@/types';

interface CustomersIndexFilters {
    zone_uuid: string | null;
    status: CustomerStatus | null;
    level: string | null;
    search: string | null;
}

interface CustomersIndexProps {
    customers: PaginatedResponse<Customer>;
    zones: Zone[];
    filters: CustomersIndexFilters;
}

const statusOptions: CustomerStatus[] = ['active', 'passive', 'disconnected', 'suspended'];

export default function CustomersIndex({ customers, zones, filters }: CustomersIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');

    useEffect(() => {
        const timeout = setTimeout(() => {
            if (search !== (filters.search ?? '')) {
                applyFilters({ search: search || undefined });
            }
        }, 350);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    function applyFilters(overrides: Record<string, string | undefined>) {
        router.get(
            '/customers',
            {
                zone_uuid: filters.zone_uuid ?? undefined,
                status: filters.status ?? undefined,
                level: filters.level ?? undefined,
                search: filters.search ?? undefined,
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AppLayout title="Customers">
            <Head title="Customers" />

            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-wrap items-end gap-3">
                    <TextInput
                        id="search"
                        label="Search"
                        placeholder="Name or phone"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-56"
                    />
                    <SelectInput
                        id="zone_uuid"
                        label="Zone"
                        value={filters.zone_uuid ?? ''}
                        onChange={(e) => applyFilters({ zone_uuid: e.target.value || undefined })}
                    >
                        <option value="">All zones</option>
                        {zones.map((zone) => (
                            <option key={zone.uuid} value={zone.uuid}>
                                {zone.name}
                            </option>
                        ))}
                    </SelectInput>
                    <SelectInput
                        id="status"
                        label="Status"
                        value={filters.status ?? ''}
                        onChange={(e) => applyFilters({ status: e.target.value || undefined })}
                    >
                        <option value="">All statuses</option>
                        {statusOptions.map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </SelectInput>
                </div>
                <Link
                    href="/customers/create"
                    className="inline-flex items-center justify-center gap-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                >
                    Add Customer
                </Link>
            </div>

            {customers.data.length === 0 ? (
                <EmptyState title="No customers found" description="Try adjusting your filters or add a new customer." />
            ) : (
                <Table>
                    <TableHead>
                        <Th>Name</Th>
                        <Th>Phone</Th>
                        <Th>Zone</Th>
                        <Th>Bill</Th>
                        <Th>Level</Th>
                        <Th>Status</Th>
                        <Th>Actions</Th>
                    </TableHead>
                    <TableBody>
                        {customers.data.map((customer) => (
                            <tr key={customer.uuid}>
                                <Td>
                                    <Link href={`/customers/${customer.uuid}`} className="font-medium text-blue-700 hover:underline">
                                        {customer.name}
                                    </Link>
                                </Td>
                                <Td>{customer.phone ?? '—'}</Td>
                                <Td>{customer.zone_name}</Td>
                                <Td>{formatCurrency(customer.bill)}</Td>
                                <Td className="capitalize">{customer.level}</Td>
                                <Td>
                                    <StatusBadge status={customer.status} />
                                </Td>
                                <Td>
                                    <div className="flex gap-3">
                                        <Link href={`/customers/${customer.uuid}/edit`} className="text-sm text-blue-700 hover:underline">
                                            Edit
                                        </Link>
                                        <Link
                                            href={`/customers/${customer.uuid}`}
                                            method="delete"
                                            as="button"
                                            onBefore={() => confirm('Delete this customer?')}
                                            className="text-sm text-red-600 hover:underline"
                                        >
                                            Delete
                                        </Link>
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </TableBody>
                </Table>
            )}

            <Pagination links={customers.links} />
        </AppLayout>
    );
}
