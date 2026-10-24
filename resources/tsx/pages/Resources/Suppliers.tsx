import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import { IconPencil, IconPlus, IconPower, IconSearch, IconUserCheck } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { ResourcesTabs } from '@/components/resources/ResourcesTabs';
import { StaffMatchWarning, TextArea, formatDate } from '@/components/resources/ExpenseUi';
import type { NamedRef, PageProps, SupplierStatus } from '@/types';

interface SupplierRow {
    uuid: string;
    name: string;
    phone: string | null;
    momo_number: string | null;
    location: string | null;
    notes: string | null;
    status: SupplierStatus;
    created_by: NamedRef | null;
    approved_by: NamedRef | null;
    approved_at: string | null;
    expenditures_count: number | null;
    staff_matches: string[];
    is_mine: boolean;
}

interface SuppliersProps {
    suppliers: SupplierRow[];
    can_manage: boolean;
    can_create: boolean;
}

interface SupplierForm {
    name: string;
    phone: string;
    momo_number: string;
    location: string;
    notes: string;
}

const STATUS_BADGE: Record<SupplierStatus, { label: string; tone: 'yellow' | 'green' | 'slate' }> = {
    pending: { label: 'Awaiting approval', tone: 'yellow' },
    active: { label: 'Approved', tone: 'green' },
    inactive: { label: 'Switched off', tone: 'slate' },
};

export default function Suppliers({ suppliers, can_manage, can_create }: SuppliersProps) {
    const { errors } = usePage<PageProps & { errors: Record<string, string> }>().props;
    const [showAdd, setShowAdd] = useState(false);
    const [editing, setEditing] = useState<SupplierRow | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (term === '') {
            return suppliers;
        }

        return suppliers.filter((supplier) =>
            [supplier.name, supplier.phone, supplier.momo_number, supplier.location].some((value) => value?.toLowerCase().includes(term)),
        );
    }, [suppliers, search]);

    const pendingCount = suppliers.filter((supplier) => supplier.status === 'pending').length;

    function act(url: string, key: string, confirmMessage?: string) {
        if (confirmMessage && !window.confirm(confirmMessage)) {
            return;
        }

        router.post(url, {}, {
            preserveScroll: true,
            onStart: () => setBusy(key),
            onFinish: () => setBusy(null),
        });
    }

    return (
        <AppLayout title="Suppliers" breadcrumbs={[{ label: 'Resources', href: '/resources' }, { label: 'Suppliers' }]}>
            <Head title="Suppliers" />

            <ResourcesTabs active="suppliers" />

            <div className="mb-5 flex flex-col gap-3 animate-fade-up sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Suppliers</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Who the business pays. A new or changed supplier must be approved by a different person.
                        {pendingCount > 0 ? ` ${pendingCount} waiting.` : ''}
                    </p>
                </div>
                {can_create && !showAdd && (
                    <Button onClick={() => setShowAdd(true)} className="min-h-[44px] w-full font-semibold sm:w-auto">
                        <IconPlus size={18} stroke={2} />
                        Add supplier
                    </Button>
                )}
            </div>

            {errors?.supplier && <div className="mb-4 rounded-md border-l-4 border-red-500 bg-red-100 px-4 py-3 text-sm text-red-800">{errors.supplier}</div>}

            {can_create && showAdd && (
                <Card className="mb-4 animate-fade-up">
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">New supplier</h2>
                        <p className="mt-0.5 text-xs text-slate-500">It can be used right away, but its expenses are reviewed until someone else approves it.</p>
                    </CardHeader>
                    <CardBody>
                        <SupplierFields
                            submitLabel="Add supplier"
                            onCancel={() => setShowAdd(false)}
                            onSubmit={(form) => form.post('/resources/suppliers', { preserveScroll: true, onSuccess: () => setShowAdd(false) })}
                        />
                    </CardBody>
                </Card>
            )}

            {suppliers.length > 6 && (
                <div className="relative mb-3 max-w-sm">
                    <IconSearch size={16} stroke={2} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search name or number"
                        aria-label="Search suppliers"
                        className="w-full rounded-lg border-0 bg-white py-2.5 pr-3 pl-9 text-base text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:py-2 sm:text-sm"
                    />
                </div>
            )}

            {visible.length === 0 ? (
                <EmptyState
                    title={suppliers.length === 0 ? 'No suppliers yet' : 'No supplier matches'}
                    description={suppliers.length === 0 ? 'Add the shops, landlords and technicians you pay regularly.' : undefined}
                />
            ) : (
                <ul className="grid grid-cols-1 gap-3 animate-fade-up md:grid-cols-2 xl:grid-cols-3" style={{ animationDelay: '80ms' }}>
                    {visible.map((supplier) => {
                        const badge = STATUS_BADGE[supplier.status];

                        return (
                            <li key={supplier.uuid}>
                                <Card className={`flex h-full flex-col ${supplier.status === 'inactive' ? 'opacity-70' : ''}`}>
                                    <CardBody className="flex-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <p className="min-w-0 font-semibold break-words text-slate-900">{supplier.name}</p>
                                            <Badge tone={badge.tone}>{badge.label}</Badge>
                                        </div>
                                        <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                            <dt className="text-slate-500">Phone</dt>
                                            <dd className="text-slate-800">{supplier.phone ?? '—'}</dd>
                                            <dt className="text-slate-500">MoMo</dt>
                                            <dd className="text-slate-800">{supplier.momo_number ?? '—'}</dd>
                                            {supplier.location && (
                                                <>
                                                    <dt className="text-slate-500">Where</dt>
                                                    <dd className="text-slate-800">{supplier.location}</dd>
                                                </>
                                            )}
                                            <dt className="text-slate-500">Expenses</dt>
                                            <dd className="text-slate-800">{supplier.expenditures_count ?? 0}</dd>
                                        </dl>
                                        {supplier.notes && <p className="mt-2 text-xs text-slate-500">{supplier.notes}</p>}
                                        <p className="mt-2 text-xs text-slate-400">
                                            {supplier.created_by ? `Added by ${supplier.is_mine ? 'you' : supplier.created_by.name}` : ''}
                                            {supplier.approved_by ? ` · approved by ${supplier.approved_by.name} on ${formatDate(supplier.approved_at)}` : ''}
                                        </p>
                                        {supplier.staff_matches.length > 0 && <StaffMatchWarning matches={supplier.staff_matches} />}
                                        {supplier.status === 'pending' && supplier.is_mine && can_manage && (
                                            <p className="mt-2 text-xs text-amber-700">You added or changed this supplier, so a different person must approve it.</p>
                                        )}
                                    </CardBody>
                                    {can_manage && (
                                        <div className="flex flex-wrap gap-2 border-t border-slate-100 px-4 py-3">
                                            {supplier.status === 'pending' && !supplier.is_mine && (
                                                <Button
                                                    onClick={() => act(`/resources/suppliers/${supplier.uuid}/approve`, supplier.uuid)}
                                                    disabled={busy !== null}
                                                    variant={supplier.staff_matches.length > 0 ? 'warning' : 'primary'}
                                                    className="min-h-[40px] flex-1"
                                                >
                                                    {busy === supplier.uuid ? <LoadingSpinner className="text-white" /> : <IconUserCheck size={17} stroke={1.75} />}
                                                    Approve
                                                </Button>
                                            )}
                                            <Button variant="secondary" onClick={() => setEditing(supplier)} className="min-h-[40px] flex-1">
                                                <IconPencil size={17} stroke={1.75} />
                                                Edit
                                            </Button>
                                            {supplier.status !== 'inactive' && (
                                                <Button
                                                    variant="ghost"
                                                    onClick={() =>
                                                        act(
                                                            `/resources/suppliers/${supplier.uuid}/deactivate`,
                                                            `off-${supplier.uuid}`,
                                                            `Switch off ${supplier.name}? Past expenses stay; it just can't be chosen any more.`,
                                                        )
                                                    }
                                                    disabled={busy !== null}
                                                    className="min-h-[40px] flex-1 text-slate-600"
                                                >
                                                    <IconPower size={17} stroke={1.75} />
                                                    Switch off
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </Card>
                            </li>
                        );
                    })}
                </ul>
            )}

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing ? `Edit ${editing.name}` : 'Edit supplier'}>
                {editing && (
                    <div className="flex flex-col gap-3">
                        <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Changing the name, phone or MoMo number sends the supplier back for approval by a different person.
                        </p>
                        <SupplierFields
                            key={editing.uuid}
                            initial={editing}
                            submitLabel="Save changes"
                            onCancel={() => setEditing(null)}
                            onSubmit={(form) => form.patch(`/resources/suppliers/${editing.uuid}`, { preserveScroll: true, onSuccess: () => setEditing(null) })}
                        />
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}

type SupplierFormHandle = InertiaFormProps<SupplierForm>;

function SupplierFields({
    initial,
    submitLabel,
    onSubmit,
    onCancel,
}: {
    initial?: SupplierRow;
    submitLabel: string;
    onSubmit: (form: SupplierFormHandle) => void;
    onCancel: () => void;
}) {
    const form = useForm<SupplierForm>({
        name: initial?.name ?? '',
        phone: initial?.phone ?? '',
        momo_number: initial?.momo_number ?? '',
        location: initial?.location ?? '',
        notes: initial?.notes ?? '',
    });
    const { data, setData, processing, errors } = form;

    function submit(e: FormEvent) {
        e.preventDefault();
        onSubmit(form);
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <TextInput id={`${initial?.uuid ?? 'new'}_name`} label="Name" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput
                    id={`${initial?.uuid ?? 'new'}_phone`}
                    label="Phone"
                    type="tel"
                    inputMode="tel"
                    value={data.phone}
                    onChange={(e) => setData('phone', e.target.value)}
                    error={errors.phone}
                />
                <TextInput
                    id={`${initial?.uuid ?? 'new'}_momo`}
                    label="MoMo / Orange Money number"
                    type="tel"
                    inputMode="tel"
                    value={data.momo_number}
                    onChange={(e) => setData('momo_number', e.target.value)}
                    error={errors.momo_number}
                />
            </div>
            <TextInput
                id={`${initial?.uuid ?? 'new'}_location`}
                label="Location"
                value={data.location}
                onChange={(e) => setData('location', e.target.value)}
                error={errors.location}
            />
            <TextArea id={`${initial?.uuid ?? 'new'}_notes`} label="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} error={errors.notes} />
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button type="button" variant="secondary" onClick={onCancel} className="w-full sm:w-auto">
                    Cancel
                </Button>
                <Button type="submit" disabled={processing} className="w-full font-semibold sm:w-auto">
                    {processing && <LoadingSpinner className="text-white" />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
