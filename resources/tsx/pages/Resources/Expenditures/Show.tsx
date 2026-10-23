import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Expenditure } from '@/types';
import { SOURCE_LABELS, monthLabel } from '@/lib/expenditures';

interface HistoryEntry {
    action: string;
    at: string | null;
    user_name: string | null;
    impersonator_name: string | null;
    changes: { field: string; from: unknown; to: unknown }[];
    reason: string | null;
}

interface Props {
    expenditure: Expenditure & {
        category_uuid: string | null;
        void_reason: string | null;
        voided_by_name: string | null;
        voided_at: string | null;
        corrects: { uuid: string; spent_at: string | null } | null;
        corrections: { uuid: string; amount: string; spent_at: string | null; source: string; description: string | null }[];
    };
    voucher: { uuid: string; number: string; status: string; payee_name: string; received_by_name: string | null; amount: string } | null;
    month_closed: boolean;
    history: HistoryEntry[];
    categories: { uuid: string; name: string; is_active: boolean }[];
    can: { update: boolean; void: boolean; correct: boolean };
}

function show(value: unknown): string {
    return value === null || value === undefined || value === '' ? '—' : String(value);
}

/**
 * One expenditure (docs/plans/expenditures-and-spending-controls.md, item 8):
 * its bon, its corrections and its history from the audit log. Admin/owner
 * may re-categorise or fix the wording in an open month with a reason —
 * never the person who requested it (E7). Amounts are never edited here: a
 * wrong amount is corrected on the bon.
 */
export default function ExpenditureShow({ expenditure, voucher, month_closed, history, categories, can }: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [voidOpen, setVoidOpen] = useState(false);

    return (
        <AppLayout
            title="Expenditure"
            breadcrumbs={[
                { label: 'Resources', href: '/resources' },
                { label: 'Expenditures', href: `/resources/expenditures?month=${expenditure.spent_at.slice(0, 7)}` },
                { label: expenditure.spent_at },
            ]}
        >
            <Head title="Expenditure" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="font-display text-2xl text-slate-900">{formatCurrency(expenditure.amount)}</h1>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                        <span>{expenditure.category_name ?? '—'}</span>·<span>{expenditure.spent_at}</span>
                        <Badge tone={expenditure.source === 'voucher' ? 'blue' : expenditure.source === 'adjustment' ? 'green' : 'slate'}>{SOURCE_LABELS[expenditure.source]}</Badge>
                        {expenditure.status === 'void' && <Badge tone="red">Void</Badge>}
                        {month_closed && <Badge tone="slate">Month closed</Badge>}
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {can.update && (
                        <Button variant="secondary" onClick={() => setEditOpen(true)}>
                            Re-categorise / fix wording
                        </Button>
                    )}
                    {can.void && (
                        <Button variant="danger" onClick={() => setVoidOpen(true)}>
                            Void
                        </Button>
                    )}
                    {can.correct && voucher && (
                        <Link href={`/vouchers/${voucher.uuid}#correct`} className="inline-flex items-center rounded-lg px-3.5 py-2 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">
                            Correct the bon
                        </Link>
                    )}
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <h2 className="text-base font-semibold text-slate-900">Details</h2>
                    </CardHeader>
                    <CardBody>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-slate-500">Description</dt>
                                <dd className="text-slate-900">{show(expenditure.description)}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Notes</dt>
                                <dd className="text-slate-900">{show(expenditure.notes)}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Recorded by</dt>
                                <dd className="text-slate-900">{show(expenditure.recorded_by_name)}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Bon</dt>
                                <dd>
                                    {voucher ? (
                                        <Link href={`/vouchers/${voucher.uuid}`} className="text-blue-700 hover:underline">
                                            {voucher.number} — paid to {voucher.received_by_name ?? voucher.payee_name}
                                        </Link>
                                    ) : (
                                        'Recorded directly before every cash-out went through a bon'
                                    )}
                                </dd>
                            </div>
                            {expenditure.relates_to_period && (
                                <div>
                                    <dt className="text-slate-500">Relates to</dt>
                                    <dd className="text-slate-900">{monthLabel(expenditure.relates_to_period)}</dd>
                                </div>
                            )}
                            {expenditure.corrects && (
                                <div>
                                    <dt className="text-slate-500">Corrects</dt>
                                    <dd>
                                        <Link href={`/resources/expenditures/${expenditure.corrects.uuid}`} className="text-blue-700 hover:underline">
                                            The payout of {expenditure.corrects.spent_at}
                                        </Link>
                                    </dd>
                                </div>
                            )}
                            {expenditure.status === 'void' && (
                                <div className="sm:col-span-2">
                                    <dt className="text-slate-500">Voided</dt>
                                    <dd className="text-red-700">
                                        {expenditure.voided_by_name} · {expenditure.voided_at ? new Date(expenditure.voided_at).toLocaleString() : ''} — {expenditure.void_reason}
                                    </dd>
                                </div>
                            )}
                        </dl>
                        {expenditure.corrections.length > 0 && (
                            <div className="mt-4">
                                <h3 className="text-sm font-semibold text-slate-900">Other entries on this bon</h3>
                                <ul className="mt-2 divide-y divide-slate-100 text-sm">
                                    {expenditure.corrections.map((row) => (
                                        <li key={row.uuid} className="flex justify-between py-1.5">
                                            <Link href={`/resources/expenditures/${row.uuid}`} className="text-blue-700 hover:underline">
                                                {row.spent_at} · {row.description ?? row.source}
                                            </Link>
                                            <span className="font-medium">{formatCurrency(row.amount)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader>
                        <h2 className="text-base font-semibold text-slate-900">History</h2>
                    </CardHeader>
                    <CardBody>
                        <ol className="space-y-3 text-sm">
                            {history.map((entry, index) => (
                                <li key={index} className="border-l-2 border-slate-200 pl-3">
                                    <p className="font-medium text-slate-900">
                                        {entry.action === 'create' ? 'Recorded' : entry.action === 'update' ? 'Changed' : entry.action} by {entry.user_name ?? '—'}
                                        {entry.impersonator_name && <span className="text-red-700"> (landlord {entry.impersonator_name})</span>}
                                    </p>
                                    <p className="text-xs text-slate-500">{entry.at ? new Date(entry.at).toLocaleString() : ''}</p>
                                    {entry.changes.map((change) => (
                                        <p key={change.field} className="text-xs text-slate-600">
                                            {change.field}: {show(change.from)} → {show(change.to)}
                                        </p>
                                    ))}
                                    {entry.reason && <p className="text-xs italic text-slate-600">“{entry.reason}”</p>}
                                </li>
                            ))}
                        </ol>
                    </CardBody>
                </Card>
            </div>

            {can.update && <EditModal open={editOpen} onClose={() => setEditOpen(false)} expenditure={expenditure} categories={categories} />}
            {can.void && <VoidModal open={voidOpen} onClose={() => setVoidOpen(false)} uuid={expenditure.uuid} />}
        </AppLayout>
    );
}

function EditModal({ open, onClose, expenditure, categories }: { open: boolean; onClose: () => void; expenditure: Props['expenditure']; categories: Props['categories'] }) {
    const { data, setData, patch, processing, errors } = useForm({
        category_uuid: expenditure.category_uuid ?? '',
        description: expenditure.description ?? '',
        notes: expenditure.notes ?? '',
        reason: '',
    });

    return (
        <Modal open={open} onClose={onClose} title="Re-categorise / fix wording">
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    patch(`/resources/expenditures/${expenditure.uuid}`, { preserveScroll: true, onSuccess: onClose });
                }}
            >
                <SelectInput id="category_uuid" label="Category" value={data.category_uuid} onChange={(e) => setData('category_uuid', e.target.value)} error={errors.category_uuid}>
                    {categories.map((category) => (
                        <option key={category.uuid} value={category.uuid}>
                            {category.name}
                        </option>
                    ))}
                </SelectInput>
                <TextInput id="description" label="Description" value={data.description} onChange={(e) => setData('description', e.target.value)} error={errors.description} />
                <TextInput id="notes" label="Notes (leave empty to clear)" value={data.notes} onChange={(e) => setData('notes', e.target.value)} error={errors.notes} />
                <TextInput id="reason" label="Reason for the change" value={data.reason} onChange={(e) => setData('reason', e.target.value)} error={errors.reason} required />
                <p className="text-xs text-slate-500">The amount can&apos;t be changed here — correct the bon instead.</p>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Save
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function VoidModal({ open, onClose, uuid }: { open: boolean; onClose: () => void; uuid: string }) {
    const { data, setData, post, processing, errors } = useForm({ reason: '' });

    return (
        <Modal open={open} onClose={onClose} title="Void this expenditure">
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/resources/expenditures/${uuid}/void`, { preserveScroll: true, onSuccess: onClose });
                }}
            >
                <p className="text-sm text-slate-600">It stays on record, marked void, and stops counting in every total.</p>
                <TextInput id="void_reason" label="Reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} error={errors.reason} required />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="danger" disabled={processing}>
                        Void
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
