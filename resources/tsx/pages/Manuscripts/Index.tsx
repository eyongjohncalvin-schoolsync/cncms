import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { StatCard } from '@/components/ui/StatCard';
import { Button } from '@/components/ui/Button';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { Modal } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Manuscript, ManuscriptSummary, PageProps, PaginatedResponse, Zone } from '@/types';

interface ManuscriptFilters {
    period?: string;
    zone_uuid?: string;
    status?: string;
}

interface ManuscriptsIndexProps {
    period: string;
    filters: ManuscriptFilters;
    manuscripts: PaginatedResponse<Manuscript>;
    summary: ManuscriptSummary;
    zones: Zone[];
}

const EXPORT_ROLES = ['super', 'admin', 'manager'];
const CALCULATE_ROLES = ['super', 'admin'];

export default function ManuscriptsIndex({ period, filters, manuscripts, summary, zones }: ManuscriptsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role ?? null;
    const canExport = role !== null && EXPORT_ROLES.includes(role);
    const canCalculate = role !== null && CALCULATE_ROLES.includes(role);

    const [confirmOpen, setConfirmOpen] = useState(false);

    const calculateForm = useForm({ period });

    function applyFilter(next: Partial<ManuscriptFilters>) {
        router.get('/manuscripts', { ...filters, period, ...next }, { preserveState: true, replace: true });
    }

    function submitCalculate() {
        calculateForm.post('/manuscripts/calculate', {
            onSuccess: () => setConfirmOpen(false),
        });
    }

    const exportParams = new URLSearchParams();
    exportParams.set('period', period);
    if (filters.zone_uuid) exportParams.set('zone_uuid', filters.zone_uuid);
    if (filters.status) exportParams.set('status', filters.status);

    return (
        <AppLayout title="Manuscripts">
            <Head title="Manuscripts" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold text-slate-900">Manuscripts — Current Period</h1>
                <div className="flex gap-2">
                    {canExport && (
                        <a
                            href={`/manuscripts/export?${exportParams.toString()}`}
                            className="inline-flex items-center justify-center gap-1.5 rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
                        >
                            Export
                        </a>
                    )}
                    {canCalculate && (
                        <Button variant="secondary" onClick={() => setConfirmOpen(true)}>
                            Run Manuscript Calculation
                        </Button>
                    )}
                </div>
            </div>

            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <StatCard label="Period" value={period} />
                <StatCard label="Total Customers" value={summary.total_customers.toLocaleString()} />
                <StatCard label="Total Billed" value={formatCurrency(summary.total_bill)} />
                <StatCard label="Total Arrears" value={formatCurrency(summary.total_arrears)} />
                <StatCard label="Collection Rate" value={`${summary.collection_rate}%`} hint={formatCurrency(summary.total_collected)} />
            </div>

            <Card className="mb-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <TextInput
                        id="period"
                        label="Period"
                        type="month"
                        value={period}
                        onChange={(e) => applyFilter({ period: e.target.value })}
                    />
                    <SelectInput
                        id="zone"
                        label="Zone"
                        value={filters.zone_uuid ?? ''}
                        onChange={(e) => applyFilter({ zone_uuid: e.target.value || undefined })}
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
                        onChange={(e) => applyFilter({ status: e.target.value || undefined })}
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="passive">Passive</option>
                        <option value="disconnected">Disconnected</option>
                        <option value="suspended">Suspended</option>
                    </SelectInput>
                </div>
            </Card>

            {manuscripts.data.length === 0 ? (
                <EmptyState title="No manuscripts for this period" description="Run the manuscript calculation to generate one." />
            ) : (
                <>
                    <Table>
                        <TableHead>
                            <Th>#</Th>
                            <Th>Name</Th>
                            <Th>Code</Th>
                            <Th>Phone</Th>
                            <Th>Zone</Th>
                            <Th>Level</Th>
                            <Th>Bill</Th>
                            <Th>Arrears</Th>
                            <Th>Credit</Th>
                            <Th>Expiry</Th>
                            <Th>Total Bill</Th>
                            <Th>Status</Th>
                        </TableHead>
                        <TableBody>
                            {manuscripts.data.map((manuscript, index) => (
                                <tr key={manuscript.customer_uuid}>
                                    <Td>{(manuscripts.meta.current_page - 1) * manuscripts.meta.per_page + index + 1}</Td>
                                    <Td>{manuscript.customer_name}</Td>
                                    <Td className="font-mono text-xs uppercase">{manuscript.customer_code}</Td>
                                    <Td>{manuscript.phone ?? '—'}</Td>
                                    <Td>{manuscript.zone_name ?? '—'}</Td>
                                    <Td>{manuscript.level}</Td>
                                    <Td>{formatCurrency(manuscript.bill)}</Td>
                                    <Td>{formatCurrency(manuscript.total_arrears)}</Td>
                                    <Td>{formatCurrency(manuscript.credit)}</Td>
                                    <Td>{manuscript.payment_expiration ?? '—'}</Td>
                                    <Td>{formatCurrency(manuscript.total_bill)}</Td>
                                    <Td>
                                        <StatusBadge status={manuscript.status} />
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination links={manuscripts.links} />
                </>
            )}

            <Modal open={confirmOpen} onClose={() => setConfirmOpen(false)} title="Run Manuscript Calculation">
                <p className="text-sm text-slate-600">
                    This recalculates bills, arrears and credit for every customer for period <strong>{period}</strong>. Continue?
                </p>
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="secondary" onClick={() => setConfirmOpen(false)} disabled={calculateForm.processing}>
                        Cancel
                    </Button>
                    <Button onClick={submitCalculate} disabled={calculateForm.processing}>
                        {calculateForm.processing ? 'Running…' : 'Confirm'}
                    </Button>
                </div>
            </Modal>
        </AppLayout>
    );
}
