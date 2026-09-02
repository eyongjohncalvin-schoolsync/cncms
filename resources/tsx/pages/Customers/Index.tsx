import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { IconArchive, IconCurrencyDollar, IconPlus, IconRestore, IconUpload } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { ImportModal } from '@/components/shared/ImportModal';
import { ImportReportCard } from '@/components/shared/ImportReportCard';
import { CustomerStatusActions } from '@/components/customers/CustomerStatusActions';
import { BulkUpdateBillModal } from '@/components/customers/BulkUpdateBillModal';
import type { BulkBillTarget } from '@/components/customers/BulkUpdateBillModal';
import { ArchiveCustomerModal } from '@/components/customers/ArchiveCustomerModal';
import { Dropdown, DropdownItem, DropdownDivider } from '@/components/ui/Dropdown';
import { formatCurrency } from '@/lib/formatCurrency';
import { hasPermission } from '@/lib/permissions';
import { useDebounce } from '@/hooks/useDebounce';
import type { Customer, CustomerLevel, CustomerStatus, PageProps, PaginatedResponse, Zone } from '@/types';

interface CustomersIndexFilters {
    zone_uuid: string | null;
    status: CustomerStatus | null;
    level: CustomerLevel | null;
    search: string | null;
}

interface CustomersIndexProps {
    customers: PaginatedResponse<Customer>;
    zones: Zone[];
    filters: CustomersIndexFilters;
    /** ?archived=1 secondary view — the list shows only archived customers, each with a Restore action. */
    archived_view: boolean;
}

const statusOptions: CustomerStatus[] = ['active', 'passive', 'disconnected', 'suspended'];
const levelOptions: CustomerLevel[] = ['normal', 'Vip', 'Operator'];

export default function CustomersIndex({ customers, zones, filters, archived_view: archivedView }: CustomersIndexProps) {
    const { auth, flash } = usePage<PageProps>().props;
    // RBAC v2 Wave 4: display affordances from the shared permission matrix
    // (auth.user.permissions), not hardcoded role names. A bulk price
    // adjustment is the same "can edit customer billing" ability as the
    // single-customer edit form → `customers.update`
    // (CustomerPolicy::update / BulkUpdateCustomerBillRequest::authorize()).
    const canBulkUpdateBill = hasPermission(auth.user?.permissions, 'customers.update');
    // Archive / restore / delete — CustomerPolicy::archive()/restore()/delete()
    // → `customers.archive`.
    const canArchive = hasPermission(auth.user?.permissions, 'customers.archive');

    const [search, setSearch] = useState(filters.search ?? '');
    const [loading, setLoading] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [bulkBillTarget, setBulkBillTarget] = useState<BulkBillTarget | null>(null);
    const [archiveTarget, setArchiveTarget] = useState<Customer | null>(null);
    const debouncedSearch = useDebounce(search, 300);

    useEffect(() => {
        if (debouncedSearch !== (filters.search ?? '')) {
            applyFilters({ search: debouncedSearch || undefined });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    // Selection is scoped to whatever page/filter is currently visible — if
    // the list changes underneath (filter change, page change, a bulk
    // update just ran), stale uuids that no longer appear are dropped
    // rather than silently carried into the next bulk submission. Same rule
    // Disconnections/Index.tsx and Payments/Index.tsx's bulk selections use.
    useEffect(() => {
        const visible = new Set(customers.data.map((customer) => customer.uuid));
        setSelected((current) => new Set([...current].filter((uuid) => visible.has(uuid))));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [customers.data]);

    function toggleSelected(uuid: string) {
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

    const allSelected = customers.data.length > 0 && customers.data.every((customer) => selected.has(customer.uuid));

    function toggleSelectAll() {
        setSelected(allSelected ? new Set() : new Set(customers.data.map((customer) => customer.uuid)));
    }

    // The bulk action bar activates on EITHER an explicit checkbox
    // selection OR a non-empty filter set — a manager can filter to Zone
    // THR01 and click "Update Bills" for every matching customer without
    // ever checking a single row, which is what lets a large batch skip
    // serializing hundreds of uuids (App\Services\CustomerService::
    // resolveCustomersForBulkBillUpdate()'s filter-descriptor path).
    // Explicit selection always takes priority when both are present.
    const hasActiveFilters = Boolean(filters.zone_uuid || filters.level || filters.status || filters.search);

    function openBulkBillModal() {
        if (selected.size > 0) {
            setBulkBillTarget({ mode: 'uuids', uuids: [...selected], targetCount: selected.size });
        } else if (hasActiveFilters) {
            setBulkBillTarget({
                mode: 'filter',
                filters: { zone_uuid: filters.zone_uuid, level: filters.level, status: filters.status, search: filters.search },
                targetCount: customers.meta.total,
            });
        }
    }

    function applyFilters(overrides: Record<string, string | undefined>) {
        router.get(
            '/customers',
            {
                zone_uuid: filters.zone_uuid ?? undefined,
                status: filters.status ?? undefined,
                level: filters.level ?? undefined,
                search: filters.search ?? undefined,
                archived: archivedView ? '1' : undefined,
                ...overrides,
            },
            {
                preserveState: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    }

    // Recomputed only when the customer list itself changes, not on every
    // keystroke in the search box or other local state updates that happen
    // before the debounced navigation actually fires.
    const rows = useMemo(
        () =>
            customers.data.map((customer) => ({
                customer,
                formattedBill: formatCurrency(customer.bill),
            })),
        [customers.data],
    );

    return (
        <AppLayout title="Customers">
            <Head title="Customers" />

            <div className="animate-fade-up mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-slate-900">
                        {archivedView ? 'Archived customers' : 'Customers'}
                    </h2>
                    <p className="mt-1 text-sm text-slate-500">
                        {archivedView
                            ? 'Removed from the active register and billing runs. Their history is kept — restore any time.'
                            : 'Manage subscriber accounts, billing zones, and connection status.'}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {archivedView ? (
                        <Link
                            href="/customers"
                            className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50"
                        >
                            ← Active customers
                        </Link>
                    ) : (
                        <>
                            <Link
                                href="/customers?archived=1"
                                className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50"
                            >
                                <IconArchive size={18} stroke={2} />
                                Archived
                            </Link>
                            <Button variant="secondary" onClick={() => setImportOpen(true)} className="rounded-lg px-4 py-2.5 text-sm font-semibold">
                                <IconUpload size={18} stroke={2} />
                                Import
                            </Button>
                            <Link
                                href="/customers/create"
                                className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition-colors hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                            >
                                <IconPlus size={18} stroke={2} />
                                Add Customer
                            </Link>
                        </>
                    )}
                </div>
            </div>

            <ImportModal
                open={importOpen}
                onClose={() => setImportOpen(false)}
                action="/customers/import"
                entityLabel="Customers"
                columnsHelp="name, phone, level, location, zone, bill, others, status"
                templateUrl="/customers/import/template"
            />

            <ImportReportCard report={flash.import} expectedType="customers" />

            <div className="animate-fade-up mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4" style={{ animationDelay: '0.05s' }}>
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <div className="relative w-full sm:w-56">
                        <TextInput
                            id="search"
                            label="Search"
                            placeholder="Name or phone"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full rounded-lg bg-white"
                        />
                        {loading && (
                            <LoadingSpinner
                                className="absolute right-2 top-[calc(50%+0.4rem)] -translate-y-1/2 text-slate-400"
                                label="Loading customers"
                            />
                        )}
                    </div>
                    <SelectInput
                        id="zone_uuid"
                        label="Zone"
                        value={filters.zone_uuid ?? ''}
                        onChange={(e) => applyFilters({ zone_uuid: e.target.value || undefined })}
                        className="w-full rounded-lg bg-white sm:w-auto"
                    >
                        <option value="">All zones</option>
                        {zones.map((zone) => (
                            <option key={zone.uuid} value={zone.uuid}>
                                {zone.name}
                            </option>
                        ))}
                    </SelectInput>
                    <SelectInput
                        id="level"
                        label="Level"
                        value={filters.level ?? ''}
                        onChange={(e) => applyFilters({ level: e.target.value || undefined })}
                        className="w-full rounded-lg bg-white sm:w-auto"
                    >
                        <option value="">All levels</option>
                        {levelOptions.map((level) => (
                            <option key={level} value={level}>
                                {level}
                            </option>
                        ))}
                    </SelectInput>
                    <SelectInput
                        id="status"
                        label="Status"
                        value={filters.status ?? ''}
                        onChange={(e) => applyFilters({ status: e.target.value || undefined })}
                        className="w-full rounded-lg bg-white sm:w-auto"
                    >
                        <option value="">All statuses</option>
                        {statusOptions.map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </SelectInput>
                </div>
            </div>

            {!archivedView && canBulkUpdateBill && (selected.size > 0 || hasActiveFilters) && (
                <div
                    className="animate-fade-up mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-300 bg-blue-100 p-3"
                    style={{ animationDelay: '0.07s' }}
                >
                    <span className="text-sm font-medium text-blue-900">
                        {selected.size > 0
                            ? `${selected.size} selected`
                            : `Filtering to ${customers.meta.total} customer${customers.meta.total === 1 ? '' : 's'} — no rows checked`}
                    </span>
                    <Button type="button" onClick={openBulkBillModal} className="rounded-lg px-3 py-2 text-sm font-semibold">
                        <IconCurrencyDollar size={16} stroke={2} />
                        Update Bills
                    </Button>
                </div>
            )}

            {customers.data.length === 0 ? (
                <EmptyState title="No customers found" description="Try adjusting your filters or add a new customer." />
            ) : (
                <Card className="animate-fade-up relative p-0" style={{ animationDelay: '0.1s' }}>
                    <Table>
                        <TableHead>
                            {canBulkUpdateBill && !archivedView && (
                                <Th className="w-10">
                                    <input
                                        type="checkbox"
                                        checked={allSelected}
                                        onChange={toggleSelectAll}
                                        aria-label="Select all customers on this page"
                                        className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                    />
                                </Th>
                            )}
                            <Th>Name</Th>
                            <Th>Phone</Th>
                            <Th>Zone</Th>
                            {archivedView ? (
                                <Th>Archived</Th>
                            ) : (
                                <>
                                    <Th>Bill</Th>
                                    <Th>Level</Th>
                                    <Th>Status</Th>
                                </>
                            )}
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {rows.map(({ customer, formattedBill }) => (
                                <tr key={customer.uuid} className="transition-colors hover:bg-slate-50/75">
                                    {canBulkUpdateBill && !archivedView && (
                                        <Td>
                                            <input
                                                type="checkbox"
                                                checked={selected.has(customer.uuid)}
                                                onChange={() => toggleSelected(customer.uuid)}
                                                aria-label={`Select ${customer.name}`}
                                                className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                            />
                                        </Td>
                                    )}
                                    <Td>
                                        <Link
                                            href={`/customers/${customer.uuid}`}
                                            className="rounded font-medium text-blue-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                                        >
                                            {customer.name}
                                        </Link>
                                    </Td>
                                    <Td>{customer.phone ?? '—'}</Td>
                                    <Td>{customer.zone_name}</Td>
                                    {archivedView ? (
                                        <Td className="text-sm text-slate-500">
                                            <span className="block text-slate-700">
                                                {customer.archived_at
                                                    ? new Date(customer.archived_at).toLocaleDateString()
                                                    : '—'}
                                                {customer.archived_by_name ? ` · ${customer.archived_by_name}` : ''}
                                            </span>
                                            {customer.archived_reason && (
                                                <span className="block max-w-xs truncate" title={customer.archived_reason}>
                                                    {customer.archived_reason}
                                                </span>
                                            )}
                                        </Td>
                                    ) : (
                                        <>
                                            <Td className="font-medium text-slate-900">{formattedBill}</Td>
                                            <Td className="capitalize">{customer.level}</Td>
                                            <Td>
                                                <StatusBadge status={customer.status} />
                                            </Td>
                                        </>
                                    )}
                                    <Td>
                                        <Dropdown label={`Actions for ${customer.name}`}>
                                            <DropdownItem href={`/customers/${customer.uuid}`}>View</DropdownItem>
                                            {archivedView ? (
                                                canArchive && (
                                                    <DropdownItem
                                                        href={`/customers/${customer.uuid}/restore`}
                                                        method="patch"
                                                        icon={<IconRestore size={16} stroke={1.75} />}
                                                        variant="success"
                                                    >
                                                        Restore customer
                                                    </DropdownItem>
                                                )
                                            ) : (
                                                <>
                                                    <DropdownItem href={`/customers/${customer.uuid}/edit`}>Edit</DropdownItem>
                                                    <DropdownDivider />
                                                    <CustomerStatusActions customer={customer} variant="menu" />
                                                    {canArchive && (
                                                        <>
                                                            <DropdownDivider />
                                                            {customer.has_billing_history === false ? (
                                                                <DropdownItem
                                                                    href={`/customers/${customer.uuid}`}
                                                                    method="delete"
                                                                    onBefore={() =>
                                                                        confirm(
                                                                            `Delete ${customer.name}? This row has no billing history and will be permanently removed.`,
                                                                        )
                                                                    }
                                                                    variant="danger"
                                                                >
                                                                    Delete row
                                                                </DropdownItem>
                                                            ) : (
                                                                <DropdownItem
                                                                    onClick={() => setArchiveTarget(customer)}
                                                                    icon={<IconArchive size={16} stroke={1.75} />}
                                                                    variant="warning"
                                                                >
                                                                    Archive customer
                                                                </DropdownItem>
                                                            )}
                                                        </>
                                                    )}
                                                </>
                                            )}
                                        </Dropdown>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={customers.links} />
                    </div>
                    {loading && (
                        <div className="absolute inset-0 flex items-start justify-center rounded-lg bg-white/60 pt-10">
                            <LoadingSpinner className="text-blue-600" label="Loading customers" />
                        </div>
                    )}
                </Card>
            )}

            {canBulkUpdateBill && (
                <BulkUpdateBillModal
                    target={bulkBillTarget}
                    onClose={() => setBulkBillTarget(null)}
                    onSuccess={() => setSelected(new Set())}
                />
            )}

            {canArchive && (
                <ArchiveCustomerModal
                    open={archiveTarget !== null}
                    onClose={() => setArchiveTarget(null)}
                    customer={
                        archiveTarget
                            ? { uuid: archiveTarget.uuid, name: archiveTarget.name, arrears: archiveTarget.total_arrears ?? null }
                            : null
                    }
                />
            )}
        </AppLayout>
    );
}
