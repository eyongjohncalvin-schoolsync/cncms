import { FormEvent, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { IconEdit, IconGift, IconPlus, IconTrash } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';

interface OfferMonths {
    service_uuid: string;
    service_name: string;
    months: number;
}

interface OfferRow {
    uuid: string;
    name: string;
    description: string | null;
    fee: string;
    months: OfferMonths[];
    valid_from: string | null;
    valid_to: string | null;
    active: boolean;
    available: boolean;
    used: number;
}

interface ServiceOption {
    uuid: string;
    name: string;
    price: string;
}

interface InstallationOffersProps {
    offers: OfferRow[];
    services: ServiceOption[];
    max_free_months: number;
    compensation_months_without_approval: number;
    one_off_fees: { relocation_fee: string | null; reinstallation_fee: string | null };
}

/**
 * Settings → Installation offers (docs/plans/installation-and-promotions.md,
 * phase 2): the standard deals a manager picks when adding a customer — the
 * installation fee and the free months per service — and the company's cap
 * on free months per grant. Anything below an offer's fee or beyond its
 * months needs admin/owner approval on the customer.
 */
export default function InstallationOffers({ offers, services, max_free_months, compensation_months_without_approval, one_off_fees }: InstallationOffersProps) {
    const [modal, setModal] = useState<{ offer: OfferRow | null } | null>(null);
    const cap = useForm({ max_free_months: max_free_months, compensation_months_without_approval: compensation_months_without_approval });
    const fees = useForm({ relocation_fee: one_off_fees.relocation_fee ?? '', reinstallation_fee: one_off_fees.reinstallation_fee ?? '' });

    return (
        <AppLayout title="Installation promotions" breadcrumbs={[{ label: 'Settings', href: '/settings/company' }, { label: 'Installation promotions' }]}>
            <Head title="Settings — Installation promotions" />

            <SettingsTabs active="installation-offers" />

            <div className="mb-8 flex items-start justify-between gap-4">
                <div className="flex items-center gap-4">
                    <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-emerald-500 to-emerald-600 text-white shadow-lg shadow-emerald-500/25">
                        <IconGift size={24} stroke={1.75} />
                    </span>
                    <div>
                        <h1 className="font-display text-3xl font-semibold tracking-tight text-slate-900">Installation promotions</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Campaigns such as &quot;10,000 FCFA installation, 3 months free&quot;. Switch one on (with optional dates) and staff can pick
                            it when adding a customer; otherwise they type the fee and give the usual free months. Changing a promotion never
                            changes customers who already took it.
                        </p>
                    </div>
                </div>
                <Button onClick={() => setModal({ offer: null })} className="shrink-0">
                    <IconPlus size={16} stroke={1.9} />
                    Add promotion
                </Button>
            </div>

            <Card className="mb-6">
                <CardBody>
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            cap.patch('/settings/installation-offers/cap', { preserveScroll: true });
                        }}
                    >
                        <div className="w-48">
                            <TextInput
                                id="max_free_months"
                                type="number"
                                min="1"
                                max="12"
                                label="Most free months per grant"
                                value={String(cap.data.max_free_months)}
                                onChange={(e) => cap.setData('max_free_months', Number(e.target.value))}
                                error={cap.errors.max_free_months}
                            />
                        </div>
                        <div className="w-64">
                            <TextInput
                                id="compensation_months_without_approval"
                                type="number"
                                min="0"
                                max="12"
                                label="Compensation without approval (months a year per customer)"
                                value={String(cap.data.compensation_months_without_approval)}
                                onChange={(e) => cap.setData('compensation_months_without_approval', Number(e.target.value))}
                                error={cap.errors.compensation_months_without_approval}
                            />
                        </div>
                        <Button type="submit" variant="secondary" disabled={cap.processing}>
                            Save limits
                        </Button>
                        <p className="text-xs text-slate-500">
                            The cap applies to offers, installation grants and compensation alike. Compensation above the yearly allowance — and any zone or
                            branch-wide compensation — waits for an admin's approval. 0 = always ask.
                        </p>
                    </form>
                </CardBody>
            </Card>

            {/* Default relocation / re-installation fees — the same charge model as
                installation; empty = no default (typed each time). */}
            <Card className="mb-6">
                <CardBody>
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            fees.patch('/settings/installation-offers/one-off-fees', { preserveScroll: true });
                        }}
                    >
                        <div className="w-48">
                            <TextInput
                                id="relocation_fee"
                                type="number"
                                min="1"
                                step="0.01"
                                label="Relocation fee (FCFA)"
                                placeholder="No default"
                                value={fees.data.relocation_fee}
                                onChange={(e) => fees.setData('relocation_fee', e.target.value)}
                                error={fees.errors.relocation_fee}
                            />
                        </div>
                        <div className="w-48">
                            <TextInput
                                id="reinstallation_fee"
                                type="number"
                                min="1"
                                step="0.01"
                                label="Re-installation fee (FCFA)"
                                placeholder="No default"
                                value={fees.data.reinstallation_fee}
                                onChange={(e) => fees.setData('reinstallation_fee', e.target.value)}
                                error={fees.errors.reinstallation_fee}
                            />
                        </div>
                        <Button type="submit" variant="secondary" disabled={fees.processing}>
                            Save fees
                        </Button>
                        <p className="text-xs text-slate-500">
                            Charged when a customer moves house or is installed again. A lower fee or a waiver needs an admin or the owner to
                            approve it. No free months come with them.
                        </p>
                    </form>
                </CardBody>
            </Card>

            {offers.length === 0 && <p className="text-sm text-slate-500">No promotions yet — add one when the company runs a campaign.</p>}

            <div className="space-y-4">
                {offers.map((offer) => (
                    <Card key={offer.uuid}>
                        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex min-w-0 flex-wrap items-center gap-2.5">
                                <span className="font-medium text-slate-900">{offer.name}</span>
                                {offer.available && (
                                    <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Running</span>
                                )}
                                {!offer.available && (
                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                                        {offer.active ? 'Outside its dates' : 'Inactive'}
                                    </span>
                                )}
                                <span className="text-xs text-slate-400">
                                    {offer.used} installation{offer.used === 1 ? '' : 's'}
                                </span>
                            </div>
                            <div className="flex shrink-0 items-center gap-1.5">
                                <span className="mr-2 text-sm font-semibold text-slate-700 tabular-nums">{formatCurrency(offer.fee)}</span>
                                <button
                                    type="button"
                                    aria-label="Edit offer"
                                    onClick={() => setModal({ offer })}
                                    className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                >
                                    <IconEdit size={15} stroke={1.75} />
                                </button>
                                <button
                                    type="button"
                                    aria-label="Delete offer"
                                    onClick={() => {
                                        if (offer.used > 0) {
                                            alert(`${offer.used} installation(s) used "${offer.name}" — deactivate it instead.`);

                                            return;
                                        }

                                        if (confirm(`Delete "${offer.name}"?`)) {
                                            router.delete(`/settings/installation-offers/${offer.uuid}`);
                                        }
                                    }}
                                    className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                                >
                                    <IconTrash size={15} stroke={1.75} />
                                </button>
                            </div>
                        </CardHeader>
                        <CardBody>
                            {offer.description && <p className="mb-2 text-sm text-slate-500">{offer.description}</p>}
                            <p className="text-sm text-slate-700">
                                {offer.months.length === 0
                                    ? 'No free months.'
                                    : offer.months.map((m) => `${m.service_name}: ${m.months} free month${m.months === 1 ? '' : 's'}`).join(' · ')}
                            </p>
                            {(offer.valid_from || offer.valid_to) && (
                                <p className="mt-1 text-xs text-slate-400">
                                    Valid {offer.valid_from ? `from ${offer.valid_from}` : ''} {offer.valid_to ? `until ${offer.valid_to}` : ''}
                                </p>
                            )}
                        </CardBody>
                    </Card>
                ))}
            </div>

            {modal && <OfferFormModal offer={modal.offer} services={services} maxMonths={max_free_months} onClose={() => setModal(null)} />}
        </AppLayout>
    );
}

function OfferFormModal({
    offer,
    services,
    maxMonths,
    onClose,
}: {
    offer: OfferRow | null;
    services: ServiceOption[];
    maxMonths: number;
    onClose: () => void;
}) {
    const { data, setData, post, patch, processing, errors } = useForm({
        name: offer?.name ?? '',
        description: offer?.description ?? '',
        fee: offer?.fee ?? '10000',
        valid_from: offer?.valid_from ?? '',
        valid_to: offer?.valid_to ?? '',
        active: offer?.active ?? true,
        months: services.map((service) => ({
            service_uuid: service.uuid,
            months: offer?.months.find((m) => m.service_uuid === service.uuid)?.months ?? 0,
        })),
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        const options = { onSuccess: onClose, preserveScroll: true };

        if (offer) {
            patch(`/settings/installation-offers/${offer.uuid}`, options);
        } else {
            post('/settings/installation-offers', options);
        }
    }

    const monthErrors = Object.entries(errors).filter(([key]) => key.startsWith('months'));

    return (
        <Modal open onClose={onClose} title={offer ? `Edit ${offer.name}` : 'Add promotion'}>
            <form onSubmit={submit} className="space-y-4">
                <TextInput id="offer-name" label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                <TextInput
                    id="offer-description"
                    label="Description (optional)"
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    error={errors.description}
                />
                <TextInput
                    id="offer-fee"
                    type="number"
                    min="0"
                    step="1"
                    label="Installation fee (FCFA)"
                    value={data.fee}
                    onChange={(e) => setData('fee', e.target.value)}
                    error={errors.fee}
                    required
                />

                <fieldset>
                    <legend className="mb-1.5 text-sm font-medium text-slate-700">Free months per service (0 – {maxMonths})</legend>
                    <div className="space-y-2">
                        {services.map((service, index) => (
                            <div key={service.uuid} className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
                                <span className="text-sm text-slate-700">{service.name}</span>
                                <input
                                    type="number"
                                    min={0}
                                    max={maxMonths}
                                    aria-label={`Free months for ${service.name}`}
                                    value={data.months[index]?.months ?? 0}
                                    onChange={(e) =>
                                        setData(
                                            'months',
                                            data.months.map((row, i) => (i === index ? { ...row, months: Number(e.target.value) } : row)),
                                        )
                                    }
                                    className="w-20 rounded-lg border border-slate-300 px-2 py-1 text-right text-sm"
                                />
                            </div>
                        ))}
                    </div>
                    {monthErrors.map(([key, message]) => (
                        <p key={key} className="mt-1 text-xs text-red-600">
                            {message}
                        </p>
                    ))}
                </fieldset>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <TextInput
                        id="offer-valid-from"
                        type="date"
                        label="Valid from (optional)"
                        value={data.valid_from}
                        onChange={(e) => setData('valid_from', e.target.value)}
                        error={errors.valid_from}
                    />
                    <TextInput
                        id="offer-valid-to"
                        type="date"
                        label="Valid until (optional)"
                        value={data.valid_to}
                        onChange={(e) => setData('valid_to', e.target.value)}
                        error={errors.valid_to}
                    />
                </div>

                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" checked={data.active} onChange={(e) => setData('active', e.target.checked)} className="h-4 w-4 rounded border-slate-300" />
                    Switched on (staff can pick it on the add-customer form between its dates)
                </label>

                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                        {offer ? 'Save' : 'Add offer'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
