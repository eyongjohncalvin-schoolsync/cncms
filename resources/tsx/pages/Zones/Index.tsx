import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { TextInput } from '@/components/ui/TextInput';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import type { PaginatedResponse, Zone } from '@/types';

interface ZonesIndexProps {
    zones: PaginatedResponse<Zone>;
    filters: { search: string | null };
}

export default function ZonesIndex({ zones, filters }: ZonesIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');

    useEffect(() => {
        const timeout = setTimeout(() => {
            if (search !== (filters.search ?? '')) {
                router.get('/zones', { search: search || undefined }, { preserveState: true, replace: true });
            }
        }, 350);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <AppLayout title="Zones">
            <Head title="Zones" />

            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <TextInput
                    id="search"
                    label="Search"
                    placeholder="Zone name"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="w-56"
                />
                <Link
                    href="/zones/create"
                    className="inline-flex items-center justify-center gap-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                >
                    Add Zone
                </Link>
            </div>

            {zones.data.length === 0 ? (
                <EmptyState title="No zones found" description="Add a zone to get started." />
            ) : (
                <Table>
                    <TableHead>
                        <Th>Name</Th>
                        <Th>Town</Th>
                        <Th>Customers</Th>
                        <Th>Actions</Th>
                    </TableHead>
                    <TableBody>
                        {zones.data.map((zone) => (
                            <tr key={zone.uuid}>
                                <Td className="font-medium text-slate-900">{zone.name}</Td>
                                <Td>{zone.town}</Td>
                                <Td>{zone.customer_count ?? 0}</Td>
                                <Td>
                                    <div className="flex gap-3">
                                        <Link href={`/zones/${zone.uuid}/edit`} className="text-sm text-blue-700 hover:underline">
                                            Edit
                                        </Link>
                                        <Link
                                            href={`/zones/${zone.uuid}`}
                                            method="delete"
                                            as="button"
                                            onBefore={() => confirm('Delete this zone?')}
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

            <Pagination links={zones.links} />
        </AppLayout>
    );
}
