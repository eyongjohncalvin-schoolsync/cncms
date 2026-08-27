import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { IconCurrencyDollar, IconPlus, IconUpload } from '@tabler/icons-react';
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
import { Dropdown, DropdownItem, DropdownDivider } from '@/components/ui/Dropdown';
import { formatCurrency } from '@/lib/formatCurrency';
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
}

const statusOptions: CustomerStatus[] = ['active', 'passive', 'disconnected', 'suspended'];
const levelOptions: CustomerLevel[] = ['normal', 'Vip', 'Operator'];

export default function CustomersIndex({ customers, zones, filters }: CustomersIndexProps) {
    const { auth, flash } = usePage<PageProps>().props;
    const role = auth.user?.role ?? null;
    // Same super/admin/manager gate as the single-customer edit form
    // (App\Policies\CustomerPolicy::update()) — a bulk price adjustment is
    // the same "can edit customer billing" ability applied to many rows,
    // not a distinct one. See BulkUpdateCustomerBillRequest::authorize().
    const canBulkUpdateBill = role === 'super' || role === 'admin' || role === 'manager';

    const [search, setSearch] = useState(filters.search ?? '');
    const [loading, setLoading] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [bulkBillTarget, setBulkBillTarget] = useState<BulkBillTarget | null>(null);
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
                    <h2 className="font-display text-2xl font-bold tracking-tight text-slate-900">Customers</h2>
                    <p className="mt-1 text-sm text-slate-500">Manage subscriber accounts, billing zones, and connection status.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
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

            {canBulkUpdateBill && (selected.size > 0 || hasActiveFilters) && (
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
                            {canBulkUpdateBill && (
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
                            <Th>Bill</Th>
                            <Th>Level</Th>
                            <Th>Status</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {rows.map(({ customer, formattedBill }) => (
                                <tr key={customer.uuid} className="transition-colors hover:bg-slate-50/75">
                                    {canBulkUpdateBill && (
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
                                    <Td className="font-medium text-slate-900">{formattedBill}</Td>
                                    <Td className="capitalize">{customer.level}</Td>
                                    <Td>
                                        <StatusBadge status={customer.status} />
                                    </Td>
                                    <Td>
                                        <Dropdown label={`Actions for ${customer.name}`}>
                                            <DropdownItem href={`/customers/${customer.uuid}`}>View</DropdownItem>
                                            <DropdownItem href={`/customers/${customer.uuid}/edit`}>Edit</DropdownItem>
                                            <DropdownDivider />
                                            <CustomerStatusActions customer={customer} variant="menu" />
                                            <DropdownDivider />
                                            <DropdownItem
                                                href={`/customers/${customer.uuid}`}
                                                method="delete"
                                                onBefore={() => confirm('Delete this customer?')}
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
        </AppLayout>
    );
}
