import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { IconAlertTriangle, IconBuildingSkyscraper, IconCircleCheck, IconPlus } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { EmptyState } from '@/components/ui/EmptyState';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Modal } from '@/components/ui/Modal';
import type { LandlordTenant, RegistrationStatus } from '@/types';

const STATUS_TABS: { label: string; value: RegistrationStatus | null }[] = [
    { label: 'All', value: null },
    { label: 'Pending', value: 'pending' },
    { label: 'Approved', value: 'approved' },
    { label: 'Rejected', value: 'rejected' },
];

const STATUS_TONE: Record<RegistrationStatus, 'yellow' | 'green' | 'red'> = {
    pending: 'yellow',
    approved: 'green',
    rejected: 'red',
};

const STATUS_LABEL: Record<RegistrationStatus, string> = {
    pending: 'Pending review',
    approved: 'Approved',
    rejected: 'Rejected',
};

interface Confirmation {
    tenant: LandlordTenant;
    action: 'approve' | 'reject';
}

export default function LandlordTenantsIndex({
    tenants,
    filters,
}: {
    tenants: LandlordTenant[];
    filters: { status: RegistrationStatus | null };
}) {
    const [isLoading, setIsLoading] = useState(false);
    const [confirmation, setConfirmation] = useState<Confirmation | null>(null);
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', () => setIsLoading(true));
        const removeFinish = router.on('finish', () => setIsLoading(false));

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    function closeConfirmation() {
        setConfirmation(null);
        setReason('');
    }

    function submitConfirmation() {
        if (!confirmation) {
            return;
        }

        const { tenant, action } = confirmation;
        setSubmitting(true);

        router.post(
            `/landlord/tenants/${tenant.id}/${action}`,
            action === 'reject' ? { reason } : {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmitting(false);
                    closeConfirmation();
                },
            },
        );
    }

    return (
        <LandlordLayout title="Tenants" breadcrumbs={[{ label: 'Landlord' }, { label: 'Tenants' }]}>
            <Head title="Landlord — Tenants" />

            <div className="animate-fade-up mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                        <IconBuildingSkyscraper size={20} stroke={1.75} />
                    </span>
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="font-display text-2xl font-semibold text-slate-900">Tenants</h1>
                            {isLoading && <LoadingSpinner className="text-blue-600" />}
                        </div>
                        <p className="text-sm text-slate-500">LCO clients ShalomTech manages on this platform.</p>
                    </div>
                </div>
                <Link
                    href="/landlord/tenants/create"
                    className="inline-flex items-center justify-center gap-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700"
                >
                    <IconPlus size={16} stroke={2} />
                    Add Tenant
                </Link>
            </div>

            <div
                className="animate-fade-up mb-4 flex flex-wrap gap-1"
                style={{ animationDelay: '80ms' }}
                role="group"
                aria-label="Filter tenants by registration status"
            >
                {STATUS_TABS.map((tab) => {
                    const isActive = (filters.status ?? null) === tab.value;

                    return (
                        <Link
                            key={tab.label}
                            href={tab.value ? `/landlord/tenants?status=${tab.value}` : '/landlord/tenants'}
                            aria-current={isActive ? 'page' : undefined}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                isActive
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'
                            }`}
                        >
                            {tab.label}
                        </Link>
                    );
                })}
            </div>

            {tenants.length === 0 ? (
                <EmptyState
                    title="No tenants found"
                    description={
                        filters.status
                            ? `No workspaces with status "${filters.status}".`
                            : 'Add a tenant to get started.'
                    }
                />
            ) : (
                <Card className="animate-fade-up p-0" style={{ animationDelay: '160ms' }}>
                    <Table>
                        <TableHead>
                            <Th>Name</Th>
                            <Th>Slug / ID</Th>
                            <Th>Domain</Th>
                            <Th>Active</Th>
                            <Th>Registration</Th>
                            <Th>Created</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {tenants.map((tenant) => (
                                <tr key={tenant.id} className="transition-colors hover:bg-slate-50/70">
                                    <Td className="font-medium text-slate-900">{tenant.name}</Td>
                                    <Td>{tenant.slug}</Td>
                                    <Td>{tenant.domain ?? '—'}</Td>
                                    <Td>
                                        <Badge tone={tenant.is_active ? 'green' : 'slate'}>
                                            {tenant.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </Td>
                                    <Td>
                                        <Badge tone={STATUS_TONE[tenant.registration_status]}>
                                            {STATUS_LABEL[tenant.registration_status]}
                                        </Badge>
                                        {tenant.registration_status === 'rejected' && tenant.rejection_reason && (
                                            <p className="mt-1 max-w-xs text-xs text-slate-500">
                                                {tenant.rejection_reason}
                                            </p>
                                        )}
                                    </Td>
                                    <Td>{tenant.created_at ? new Date(tenant.created_at).toLocaleDateString() : '—'}</Td>
                                    <Td>
                                        <div className="flex flex-wrap items-center gap-1">
                                            <Link
                                                href={`/landlord/tenants/${tenant.id}/edit`}
                                                aria-label={`Edit ${tenant.name}`}
                                                className="rounded-md px-2 py-1 text-sm font-medium text-blue-700 transition-colors hover:bg-blue-50"
                                            >
                                                Edit
                                            </Link>
                                            {tenant.registration_status === 'pending' && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() => setConfirmation({ tenant, action: 'approve' })}
                                                        aria-label={`Approve ${tenant.name}`}
                                                        className="rounded-md px-2 py-1 text-sm font-medium text-green-700 transition-colors hover:bg-green-50"
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setConfirmation({ tenant, action: 'reject' })}
                                                        aria-label={`Reject ${tenant.name}`}
                                                        className="rounded-md px-2 py-1 text-sm font-medium text-red-700 transition-colors hover:bg-red-50"
                                                    >
                                                        Reject
                                                    </button>
                                                </>
                                            )}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            <Modal
                open={confirmation !== null}
                onClose={closeConfirmation}
                title={
                    confirmation
                        ? confirmation.action === 'approve'
                            ? `Approve "${confirmation.tenant.name}"?`
                            : `Reject "${confirmation.tenant.name}"?`
                        : undefined
                }
            >
                {confirmation && (
                    <div>
                        <div
                            className={`mb-4 inline-flex h-12 w-12 items-center justify-center rounded-full ${
                                confirmation.action === 'approve'
                                    ? 'bg-green-100 text-green-600'
                                    : 'bg-red-100 text-red-600'
                            }`}
                        >
                            {confirmation.action === 'approve' ? (
                                <IconCircleCheck size={26} stroke={1.75} />
                            ) : (
                                <IconAlertTriangle size={26} stroke={1.75} />
                            )}
                        </div>

                        <p className="text-sm text-slate-600">
                            {confirmation.action === 'approve'
                                ? 'The registrant will be able to reach their workspace dashboard on their next request. No re-login is required.'
                                : 'The workspace stays inert — its data is not deleted, so it can still be reviewed later.'}
                        </p>

                        {confirmation.action === 'reject' && (
                            <div className="mt-4">
                                <label htmlFor="reject-reason" className="mb-1 block text-sm font-medium text-slate-700">
                                    Reason (optional)
                                </label>
                                <textarea
                                    id="reject-reason"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    rows={3}
                                    className="w-full rounded-lg border-0 px-3.5 py-2.5 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 focus:outline-none"
                                    placeholder="Shown only to landlord staff reviewing this workspace later."
                                />
                            </div>
                        )}

                        <div className="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-4">
                            <Button type="button" variant="secondary" onClick={closeConfirmation} disabled={submitting}>
                                Cancel
                            </Button>
                            <button
                                type="button"
                                onClick={submitConfirmation}
                                disabled={submitting}
                                className={`inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-semibold text-white transition-colors disabled:opacity-50 ${
                                    confirmation.action === 'approve'
                                        ? 'bg-green-600 hover:bg-green-700'
                                        : 'bg-red-600 hover:bg-red-700'
                                }`}
                            >
                                {submitting && <LoadingSpinner className="text-white" />}
                                {submitting
                                    ? 'Saving…'
                                    : confirmation.action === 'approve'
                                      ? 'Approve'
                                      : 'Reject'}
                            </button>
                        </div>
                    </div>
                )}
            </Modal>
        </LandlordLayout>
    );
}
