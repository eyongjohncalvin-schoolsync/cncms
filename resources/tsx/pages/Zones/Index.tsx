import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { IconPlus, IconUpload } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { TextInput } from '@/components/ui/TextInput';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Button } from '@/components/ui/Button';
import { Dropdown, DropdownItem } from '@/components/ui/Dropdown';
import { ImportModal } from '@/components/shared/ImportModal';
import { ImportReportCard } from '@/components/shared/ImportReportCard';
import { useDebounce } from '@/hooks/useDebounce';
import type { PageProps, PaginatedResponse, Zone } from '@/types';

interface ZonesIndexProps {
    zones: PaginatedResponse<Zone>;
    filters: { search: string | null };
}

export default function ZonesIndex({ zones, filters }: ZonesIndexProps) {
    const { flash } = usePage<PageProps>().props;
    const [search, setSearch] = useState(filters.search ?? '');
    const [loading, setLoading] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const debouncedSearch = useDebounce(search, 300);

    useEffect(() => {
        if (debouncedSearch !== (filters.search ?? '')) {
            router.get(
                '/zones',
                { search: debouncedSearch || undefined },
                {
                    preserveState: true,
                    replace: true,
                    onStart: () => setLoading(true),
                    onFinish: () => setLoading(false),
                },
            );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    // Recomputed only when the zone list itself changes, not on every
    // keystroke in the search box before the debounced navigation fires.
    const rows = useMemo(
        () =>
            zones.data.map((zone) => ({
                zone,
                customerCount: zone.customer_count ?? 0,
            })),
        [zones.data],
    );

    return (
        <AppLayout title="Zones">
            <Head title="Zones" />

            <div className="animate-fade-up mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl text-slate-900">Zones</h2>
                    <p className="mt-1 text-sm text-slate-500">Manage the billing zones customers are grouped into.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="secondary" onClick={() => setImportOpen(true)} className="rounded-lg px-4 py-2.5 text-sm font-semibold">
                        <IconUpload size={18} stroke={2} />
                        Import
                    </Button>
                    <Link
                        href="/zones/create"
                        className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition-colors hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                    >
                        <IconPlus size={18} stroke={2} />
                        Add Zone
                    </Link>
                </div>
            </div>

            <ImportModal
                open={importOpen}
                onClose={() => setImportOpen(false)}
                action="/zones/import"
                entityLabel="Zones"
                columnsHelp="name, town"
                templateUrl="/zones/import/template"
            />

            <ImportReportCard report={flash.import} expectedType="zones" />

            <div
                className="animate-fade-up mb-4 flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:flex-wrap sm:items-end"
                style={{ animationDelay: '0.05s' }}
            >
                <TextInput
                    id="search"
                    label="Search"
                    placeholder="Zone name"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="w-full rounded-lg bg-white sm:w-72"
                />
                {loading && <LoadingSpinner className="mb-2 text-slate-400" label="Loading zones" />}
            </div>

            {zones.data.length === 0 ? (
                <div className="animate-fade-up" style={{ animationDelay: '0.1s' }}>
                    <EmptyState title="No zones found" description="Add a zone to get started." />
                </div>
            ) : (
                <div className="animate-fade-up relative" style={{ animationDelay: '0.1s' }}>
                    <Table>
                        <TableHead>
                            <Th>Name</Th>
                            <Th>Branch</Th>
                            <Th>Town</Th>
                            <Th>Customers</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {rows.map(({ zone, customerCount }) => (
                                <tr key={zone.uuid} className="transition-colors hover:bg-slate-50/70">
                                    <Td className="font-medium text-slate-900">{zone.name}</Td>
                                    <Td>{zone.branch_name ?? '—'}</Td>
                                    <Td>{zone.town}</Td>
                                    <Td>{customerCount}</Td>
                                    <Td>
                                        <Dropdown label={`Actions for ${zone.name}`}>
                                            <DropdownItem href={`/zones/${zone.uuid}/edit`}>Edit</DropdownItem>
                                            <DropdownItem
                                                href={`/zones/${zone.uuid}`}
                                                method="delete"
                                                onBefore={() => confirm('Delete this zone?')}
                                                variant="danger"
                                            >
                                                Delete
                                            </DropdownItem>
                                        </Dropdown>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    {loading && (
                        <div className="absolute inset-0 flex items-start justify-center rounded-lg bg-white/60 pt-10">
                            <LoadingSpinner className="text-blue-600" label="Loading zones" />
                        </div>
                    )}
                </div>
            )}

            <Pagination links={zones.links} />
        </AppLayout>
    );
}
