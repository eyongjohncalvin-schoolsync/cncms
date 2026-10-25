import { FormEvent, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { IconCalendarDollar, IconEdit, IconPlus, IconTrash } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { InfoTip } from '@/components/ui/InfoTip';
import { Modal } from '@/components/ui/Modal';
import { Table, TableBody, TableHead, Td, Th } from '@/components/ui/Table';
import { TextInput } from '@/components/ui/TextInput';
import { formatCurrency } from '@/lib/formatCurrency';

interface OfferRow {
    uuid: string;
    name: string;
    months: number;
    price: string;
    valid_from: string | null;
    valid_to: string | null;
    active: boolean;
    on_sale: boolean;
    sold: number;
    sold_this_month: number;
    discount_this_month: string;
}

interface PrepaymentOffersProps {
    offers: OfferRow[];
    can_manage: boolean;
}

/**
 * Settings → Prepayment offers (App\Http\Controllers\SettingsPrepaymentOfferController):
 * months paid ahead at a promotional price. A collector picks the offer when
 * recording the payment; the amount and months are then fixed, so nobody has
 * to record a bigger amount to make the months come out right.
 */
export default function PrepaymentOffers({ offers, can_manage }: PrepaymentOffersProps) {
    const [editing, setEditing] = useState<{ offer: OfferRow | null } | null>(null);

    return (
        <AppLayout title="Prepayment offers" breadcrumbs={[{ label: 'Settings', href: '/settings/company' }, { label: 'Prepayment offers' }]}>
            <Head title="Settings — Prepayment offers" />

            <SettingsTabs active="prepayment-offers" />

            <div className="mb-8 flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-center gap-4">
                    <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/25">
                        <IconCalendarDollar size={24} stroke={1.75} />
                    </span>
                    <div>
                        <h1 className="font-display text-3xl font-semibold tracking-tight text-slate-900">Prepayment offers</h1>
                        <p className="mt-1 max-w-2xl text-sm text-slate-500">
                            Months paid ahead at a special price, e.g. “4 months for 10,000”. The collector picks the offer when recording the
                            payment; the amount and months are then fixed. Only customers with no unpaid debt can buy one. A branch can set its own
                            price on its branch settings.
                        </p>
                    </div>
                </div>
                {can_manage && (
                    <Button onClick={() => setEditing({ offer: null })} className="shrink-0">
                        <IconPlus size={16} stroke={1.9} />
                        Add offer
                    </Button>
                )}
            </div>

            {offers.length === 0 ? (
                <EmptyState title="No prepayment offers yet" description="Add one to let collectors sell months ahead at a special price." />
            ) : (
                <Card className="p-0">
                    <Table>
                        <TableHead>
                            <Th>Offer</Th>
                            <Th>Price</Th>
                            <Th>
                                <span className="inline-flex items-center gap-1">
                                    Per month
                                    <InfoTip label="About the per-month value" text="What each of the offer's months is worth — the price divided by the months." />
                                </span>
                            </Th>
                            <Th>On sale</Th>
                            <Th>Sold this month</Th>
                            <Th>Discount this month</Th>
                            {can_manage && <Th>Actions</Th>}
                        </TableHead>
                        <TableBody>
                            {offers.map((offer) => (
                                <tr key={offer.uuid} className="hover:bg-slate-50/70">
                                    <Td>
                                        <p className="font-medium text-slate-900">{offer.name}</p>
                                        <p className="text-xs text-slate-500">
                                            {offer.months} months{offer.valid_from || offer.valid_to ? ` · ${offer.valid_from ?? '…'} → ${offer.valid_to ?? '…'}` : ''}
                                        </p>
                                    </Td>
                                    <Td className="whitespace-nowrap">{formatCurrency(Number(offer.price))}</Td>
                                    <Td className="whitespace-nowrap">{formatCurrency(Number(offer.price) / offer.months)}</Td>
                                    <Td>{offer.on_sale ? <Badge tone="green">On sale</Badge> : <Badge tone="slate">{offer.active ? 'Not in dates' : 'Off'}</Badge>}</Td>
                                    <Td>
                                        {offer.sold_this_month}
                                        <span className="text-xs text-slate-400"> · {offer.sold} in all</span>
                                    </Td>
                                    <Td className="whitespace-nowrap">{formatCurrency(Number(offer.discount_this_month))}</Td>
                                    {can_manage && (
                                        <Td className="whitespace-nowrap">
                                            <div className="flex items-center gap-1">
                                                <Button variant="ghost" onClick={() => setEditing({ offer })} aria-label={`Edit ${offer.name}`}>
                                                    <IconEdit size={16} />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    aria-label={`Delete ${offer.name}`}
                                                    onClick={() => {
                                                        if (confirm(offer.sold > 0 ? `${offer.name} was already sold — switch it off?` : `Delete ${offer.name}?`)) {
                                                            router.delete(`/settings/prepayment-offers/${offer.uuid}`, { preserveScroll: true });
                                                        }
                                                    }}
                                                >
                                                    <IconTrash size={16} />
                                                </Button>
                                            </div>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            {editing && <OfferModal offer={editing.offer} onClose={() => setEditing(null)} />}
        </AppLayout>
    );
}

function OfferModal({ offer, onClose }: { offer: OfferRow | null; onClose: () => void }) {
    const form = useForm({
        name: offer?.name ?? '',
        months: offer?.months ?? 4,
        price: offer?.price ?? '',
        valid_from: offer?.valid_from ?? '',
        valid_to: offer?.valid_to ?? '',
        active: offer?.active ?? true,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (offer) {
            form.patch(`/settings/prepayment-offers/${offer.uuid}`, options);
        } else {
            form.post('/settings/prepayment-offers', options);
        }
    }

    const perMonth = Number(form.data.price) > 0 && form.data.months > 0 ? Number(form.data.price) / form.data.months : null;

    return (
        <Modal open onClose={onClose} title={offer ? `Edit ${offer.name}` : 'Add prepayment offer'}>
            <form onSubmit={submit} className="flex flex-col gap-4">
                <TextInput id="name" label="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={form.errors.name} placeholder="4 MONTHS PROMO" required />
                <div className="grid grid-cols-2 gap-3">
                    <TextInput
                        id="months"
                        type="number"
                        min="2"
                        max="36"
                        label="Months"
                        value={String(form.data.months)}
                        onChange={(e) => form.setData('months', Number(e.target.value))}
                        error={form.errors.months}
                        required
                    />
                    <TextInput
                        id="price"
                        type="number"
                        min="1"
                        step="0.01"
                        label="Price (FCFA)"
                        value={form.data.price}
                        onChange={(e) => form.setData('price', e.target.value)}
                        error={form.errors.price}
                        required
                    />
                </div>
                {perMonth !== null && <p className="text-xs text-slate-500">Each month is worth {formatCurrency(perMonth)}.</p>}
                <div className="grid grid-cols-2 gap-3">
                    <TextInput id="valid_from" type="date" label="On sale from (optional)" value={form.data.valid_from} onChange={(e) => form.setData('valid_from', e.target.value)} error={form.errors.valid_from} />
                    <TextInput id="valid_to" type="date" label="Until (optional)" value={form.data.valid_to} onChange={(e) => form.setData('valid_to', e.target.value)} error={form.errors.valid_to} />
                </div>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} className="h-4 w-4 rounded border-slate-300 text-blue-600" />
                    On sale
                </label>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Saving…' : 'Save offer'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
