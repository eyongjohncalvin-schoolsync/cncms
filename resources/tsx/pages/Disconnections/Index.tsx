import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Badge } from '@/components/ui/Badge';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Dropdown } from '@/components/ui/Dropdown';
import { CustomerStatusActions } from '@/components/customers/CustomerStatusActions';
import { BulkStatusModal } from '@/components/customers/BulkStatusModal';
import type { StatusAction } from '@/components/customers/CustomerStatusActions';
import { formatCurrency } from '@/lib/formatCurrency';
import { hasPermission } from '@/lib/permissions';
import { useDebounce } from '@/hooks/useDebounce';
import type { Customer, CustomerStatus, PageProps, PaginatedResponse, Zone } from '@/types';

interface DisconnectionsIndexFilters {
    zone_uuid: string | null;
    status: CustomerStatus | null;
    search: string | null;
    eligible: boolean;
}

interface DisconnectionsIndexProps {
    customers: PaginatedResponse<Customer>;
    zones: Zone[];
    filters: DisconnectionsIndexFilters;
    /** True when the current user is an `agent` force-scoped to their own zone. */
    isAgentScoped: boolean;
}

const statusOptions: CustomerStatus[] = ['active', 'passive', 'disconnected', 'suspended'];

// Pre-filled into the bulk note field for a disconnect started from the
// eligibility tab — App\Policies\CustomerPolicy::disconnect()/bulkDisconnect()
// still require super/admin/manager to actually submit it, but this keeps the
// audit-trail reason self-explanatory (see CustomerStatusService's doc
// comment on the automatic/system-flagged disconnect note) without forcing
// office staff to type it out every time.
const ELIGIBILITY_DEFAULT_NOTE = 'Automatic — arrears reached 3x monthly bill, past payment deadline.';

/**
 * The dedicated bulk customer-status workboard (see
 * App\Http\Controllers\DisconnectionsController's class doc). Two tabs share
 * this one page/component rather than being separate pages:
 *
 *  - Status Board (default, `eligible` absent/false) — office staff
 *    (super/admin/manager) manually select customers and disconnect/suspend/
 *    reconnect them together, exactly as before.
 *  - Flagged for Non-Payment (`?eligible=1`) — the arrears-based automatic
 *    monitor (App\Services\CustomerEligibilityService): customers whose
 *    accumulated arrears have reached 3x their monthly bill, past the
 *    current period's payment deadline. Visible to `agent` too (scoped to
 *    their own zone), but only office roles get the bulk-select/action bar
 *    since executing a disconnect stays super/admin/manager-only.
 *
 * Both tabs reuse the exact same checkbox + select-all + contextual
 * action-bar + BulkStatusModal flow — the eligibility tab is not a
 * different UX, just a different data source feeding the same table.
 */
export default function DisconnectionsIndex({ customers, zones, filters, isAgentScoped }: DisconnectionsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    // RBAC v2 Wave 4: CustomerPolicy::disconnect/suspend/reconnect (+ bulk)
    // → `customers.change_status`. The agent's zone-scoped disconnect is a
    // server-side OR-branch with no matrix permission, matching the old role
    // array's exclusion of agent from this bulk-action board.
    const canManageStatus = hasPermission(auth.user?.permissions, 'customers.change_status');

    const [search, setSearch] = useState(filters.search ?? '');
    const [loading, setLoading] = useState(false);
    const debouncedSearch = useDebounce(search, 300);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [bulkAction, setBulkAction] = useState<StatusAction | null>(null);

    useEffect(() => {
        if (filters.eligible) {
            return;
        }
        if (debouncedSearch !== (filters.search ?? '')) {
            applyFilters({ search: debouncedSearch || undefined });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    // Selection is scoped to whatever page/filter/tab is currently visible —
    // if the list changes underneath (filter change, page change, tab
    // switch, a bulk action just ran), stale uuids that no longer appear are
    // dropped rather than silently carried into the next bulk submission.
    // Same rule Payments/Index.tsx's bulk-verify selection uses.
    useEffect(() => {
        const visible = new Set(customers.data.map((customer) => customer.uuid));
        setSelected((current) => new Set([...current].filter((uuid) => visible.has(uuid))));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [customers.data]);

    function applyFilters(overrides: Record<string, string | undefined>) {
        router.get(
            '/disconnections',
            {
                zone_uuid: filters.zone_uuid ?? undefined,
                status: filters.status ?? undefined,
                search: filters.search ?? undefined,
                eligible: filters.eligible ? '1' : undefined,
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

    function switchTab(eligible: boolean) {
        setSelected(new Set());
        router.get(
            '/disconnections',
            eligible ? { eligible: '1' } : undefined,
            {
                preserveState: false,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    }

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

    const selectedCustomers = useMemo(
        () => customers.data.filter((customer) => selected.has(customer.uuid)),
        [customers.data, selected],
    );

    const rows = useMemo(
        () =>
            customers.data.map((customer) => ({
                customer,
                formattedBill: formatCurrency(customer.bill),
                formattedArrears: customer.total_arrears !== undefined ? formatCurrency(customer.total_arrears) : null,
            })),
        [customers.data],
    );

    return (
        <AppLayout title="Disconnections">
            <Head title="Disconnections" />

            <div className="animate-fade-up mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2.5">
                        <h2 className="font-display text-2xl font-bold tracking-tight text-slate-900">Disconnections</h2>
                        <Badge tone={filters.eligible ? 'red' : 'slate'}>
                            {customers.meta.total} {customers.meta.total === 1 ? 'customer' : 'customers'}
                        </Badge>
                    </div>
                    <p className="mt-1 text-sm text-slate-500">
                        {filters.eligible
                            ? 'Customers whose accumulated arrears have reached 3x their monthly bill, past the payment deadline.'
                            : 'Select several customers and disconnect, suspend, or reconnect them together in one action.'}
                    </p>
                </div>
            </div>

            {canManageStatus && (
                <div className="animate-fade-up mb-4 inline-flex gap-1 rounded-lg bg-slate-100 p-1" style={{ animationDelay: '0.02s' }}>
                    <button
                        type="button"
                        onClick={() => switchTab(false)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 ${
                            !filters.eligible ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        Status Board
                    </button>
                    <button
                        type="button"
                        onClick={() => switchTab(true)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 ${
                            filters.eligible ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        Flagged for Non-Payment
                    </button>
                </div>
            )}

            <div className="animate-fade-up mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4" style={{ animationDelay: '0.05s' }}>
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                    {!filters.eligible && (
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
                    )}
                    {!isAgentScoped && (
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
                    )}
                    {isAgentScoped && filters.zone_uuid && (
                        <p className="text-sm text-slate-500">
                            Showing your zone: <span className="font-medium text-slate-700">{zones.find((z) => z.uuid === filters.zone_uuid)?.name}</span>
                        </p>
                    )}
                    {!filters.eligible && (
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
                    )}
                </div>
            </div>

            {canManageStatus && selected.size > 0 && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-300 bg-blue-100 p-3 animate-fade-up">
                    <span className="text-sm font-medium text-blue-900">{selected.size} selected</span>
                    <div className="flex flex-wrap gap-2">
                        {!filters.eligible && (
                            <Button type="button" variant="secondary" onClick={() => setBulkAction('suspend')} className="rounded-lg px-3 py-2 text-sm font-semibold">
                                Suspend Selected
                            </Button>
                        )}
                        <Button type="button" variant="danger" onClick={() => setBulkAction('disconnect')} className="rounded-lg px-3 py-2 text-sm font-semibold">
                            Disconnect Selected
                        </Button>
                        {!filters.eligible && (
                            <Button type="button" onClick={() => setBulkAction('reconnect')} className="rounded-lg px-3 py-2 text-sm font-semibold">
                                Reconnect Selected
                            </Button>
                        )}
                    </div>
                </div>
            )}

            {customers.data.length === 0 ? (
                <EmptyState
                    title={filters.eligible ? 'No customers currently flagged' : 'No customers found'}
                    description={
                        filters.eligible
                            ? 'Nobody in view has crossed the 3x-bill arrears threshold past the payment deadline right now.'
                            : 'Try adjusting your filters.'
                    }
                />
            ) : (
                <Card className="animate-fade-up relative p-0" style={{ animationDelay: '0.1s' }}>
                    <Table>
                        <TableHead>
                            {canManageStatus && (
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
                            {filters.eligible ? (
                                <>
                                    <Th>Arrears</Th>
                                    <Th>Ratio</Th>
                                    <Th>Months Overdue</Th>
                                </>
                            ) : (
                                <>
                                    <Th>Status</Th>
                                    <Th>Reason</Th>
                                </>
                            )}
                            {canManageStatus && <Th>Actions</Th>}
                        </TableHead>
                        <TableBody>
                            {rows.map(({ customer, formattedBill, formattedArrears }) => (
                                <tr key={customer.uuid} className="transition-colors hover:bg-slate-50/75">
                                    {canManageStatus && (
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
                                    <Td>{formattedBill}</Td>
                                    {filters.eligible ? (
                                        <>
                                            <Td className="font-medium text-red-700">{formattedArrears}</Td>
                                            <Td>
                                                {customer.arrears_ratio !== undefined ? (
                                                    <Badge tone={customer.arrears_ratio >= 4 ? 'red' : 'yellow'}>
                                                        {customer.arrears_ratio.toFixed(1)}x
                                                    </Badge>
                                                ) : (
                                                    '—'
                                                )}
                                            </Td>
                                            <Td className="font-medium text-slate-900">{customer.months_overdue ?? '—'}</Td>
                                        </>
                                    ) : (
                                        <>
                                            <Td>
                                                <StatusBadge status={customer.status} />
                                            </Td>
                                            <Td className="capitalize text-slate-600">{customer.status_reason?.replace(/_/g, ' ') ?? '—'}</Td>
                                        </>
                                    )}
                                    {canManageStatus && (
                                        <Td>
                                            {/* Same Dropdown/DropdownItem kebab-menu pattern Customers/Index.tsx's
                                                and Manuscripts/Index.tsx's Actions columns use —
                                                CustomerStatusActions' own variant="menu" mode was built exactly
                                                for composing inside a parent Dropdown like this one. */}
                                            <Dropdown label={`Actions for ${customer.name}`}>
                                                <CustomerStatusActions customer={customer} variant="menu" />
                                            </Dropdown>
                                        </Td>
                                    )}
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

            {canManageStatus && (
                <BulkStatusModal
                    action={bulkAction}
                    customers={selectedCustomers}
                    onClose={() => setBulkAction(null)}
                    defaultNote={filters.eligible ? ELIGIBILITY_DEFAULT_NOTE : undefined}
                />
            )}
        </AppLayout>
    );
}
