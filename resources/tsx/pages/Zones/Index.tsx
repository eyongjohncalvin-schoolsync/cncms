import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { IconPlus, IconTrash, IconUpload } from '@tabler/icons-react';
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
import { BulkDeleteZonesModal } from '@/components/zones/BulkDeleteZonesModal';
import type { BulkDeleteZonesTarget } from '@/components/zones/BulkDeleteZonesModal';
import { hasPermission } from '@/lib/permissions';
import { useDebounce } from '@/hooks/useDebounce';
import type { PageProps, PaginatedResponse, Zone } from '@/types';

interface ZonesIndexProps {
    zones: PaginatedResponse<Zone>;
    filters: { search: string | null };
}

export default function ZonesIndex({ zones, filters }: ZonesIndexProps) {
    const { auth, flash } = usePage<PageProps>().props;
    // Same ability as the single-row Delete (ZonePolicy::delete → `zones.manage`).
    const canDelete = hasPermission(auth.user?.permissions, 'zones.manage');
    const [search, setSearch] = useState(filters.search ?? '');
    const [loading, setLoading] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [allMatching, setAllMatching] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState<BulkDeleteZonesTarget | null>(null);
    const debouncedSearch = useDebounce(search, 300);

    // Selection belongs to the rows on screen: a page/search change (or a
    // delete that just ran) drops it rather than carrying hidden uuids into
    // the next bulk delete — same rule as Customers/Index.tsx.
    useEffect(() => {
        setSelected(new Set());
        setAllMatching(false);
    }, [zones.data]);

    function toggleSelected(uuid: string) {
        setAllMatching(false);
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(uuid)) {
                next.delete(uuid);
            } else {
                next.add(uuid);
            }
            return next;
        });
    }

    const allOnPageSelected = zones.data.length > 0 && zones.data.every((zone) => selected.has(zone.uuid));
    const total = zones.meta.total;
    const selectedCount = allMatching ? total : selected.size;

    function toggleSelectAllOnPage() {
        setAllMatching(false);
        setSelected(allOnPageSelected ? new Set() : new Set(zones.data.map((zone) => zone.uuid)));
    }

    function clearSelection() {
        setAllMatching(false);
        setSelected(new Set());
    }

    function openBulkDelete() {
        setDeleteTarget(allMatching ? { mode: 'all_matching', search: filters.search } : { mode: 'uuids', uuids: [...selected] });
    }

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
                columnsHelp="name"
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

            {canDelete && selectedCount > 0 && (
                <div className="animate-fade-up mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-300 bg-blue-100 p-3">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-blue-900">
                        <span className="font-medium">
                            {allMatching ? `All ${total} matching zone${total === 1 ? '' : 's'} selected` : `${selectedCount} selected`}
                        </span>
                        {allOnPageSelected && !allMatching && total > zones.data.length && (
                            <button type="button" onClick={() => setAllMatching(true)} className="font-semibold text-blue-700 underline hover:text-blue-800">
                                Select all {total} matching zones
                            </button>
                        )}
                        <button type="button" onClick={clearSelection} className="text-blue-700 underline hover:text-blue-800">
                            Clear selection
                        </button>
                    </div>
                    <Button type="button" variant="danger" onClick={openBulkDelete} className="rounded-lg px-3 py-2 text-sm font-semibold">
                        <IconTrash size={16} stroke={2} />
                        Delete selected ({selectedCount})
                    </Button>
                </div>
            )}

            <BulkDeleteZonesModal target={deleteTarget} onClose={() => setDeleteTarget(null)} onSuccess={clearSelection} />

            {zones.data.length === 0 ? (
                <div className="animate-fade-up" style={{ animationDelay: '0.1s' }}>
                    <EmptyState title="No zones found" description="Add a zone to get started." />
                </div>
            ) : (
                <div className="animate-fade-up relative" style={{ animationDelay: '0.1s' }}>
                    <Table>
                        <TableHead>
                            {canDelete && (
                                <Th className="w-10">
                                    <input
                                        type="checkbox"
                                        checked={allOnPageSelected}
                                        onChange={toggleSelectAllOnPage}
                                        aria-label="Select all zones on this page"
                                        className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                    />
                                </Th>
                            )}
                            <Th>Name</Th>
                            <Th>Branch</Th>
                            <Th>Customers</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {rows.map(({ zone, customerCount }) => (
                                <tr key={zone.uuid} className="transition-colors hover:bg-slate-50/70">
                                    {canDelete && (
                                        <Td>
                                            <input
                                                type="checkbox"
                                                checked={allMatching || selected.has(zone.uuid)}
                                                onChange={() => toggleSelected(zone.uuid)}
                                                aria-label={`Select ${zone.name}`}
                                                className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                            />
                                        </Td>
                                    )}
                                    <Td className="font-medium text-slate-900">{zone.name}</Td>
                                    <Td>{zone.branch_name ?? '—'}</Td>
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
