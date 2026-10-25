import { Form, Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Table, TableBody, TableHead, Td, Th } from '@/components/ui/Table';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { InfoTip } from '@/components/ui/InfoTip';

interface PricedItem {
    uuid: string;
    name: string;
    hq_price: string;
    /** The branch's own price, or null when it uses headquarters'. */
    branch_price: string | null;
}

interface PriceList {
    services: (PricedItem & { variants: PricedItem[] })[];
    offers: PricedItem[];
}

interface PriceChange {
    when: string;
    item: string;
    price: string | null;
    reason: string;
    by: string | null;
}

type PriceTarget = 'service' | 'variant' | 'offer';

type Overridable =
    | 'location'
    | 'phone'
    | 'email'
    | 'tech_number'
    | 'momo_number'
    | 'momo_name'
    | 'reconnection_fine'
    | 'relocation_fee'
    | 'reinstallation_fee';

interface BranchSettingsProps {
    branch: { uuid: string; name: string; code: string | null; sends_to_hq: 'off' | 'weekly' | 'monthly' } & Record<Overridable, string | null>;
    /** Headquarters' value for each field — what the branch uses when its own is empty. */
    headquarters: Record<Overridable, string | null>;
    can_manage_branches: boolean;
    prices: PriceList;
    price_history: PriceChange[];
}

const money = (value: string) => `${Number(value).toLocaleString('fr-FR')} FCFA`;

/**
 * One item's branch price: empty = headquarters' price. A reason is always
 * required (it is the price history); services and options can also reprice
 * the branch's current subscribers.
 */
function PriceRow({ branchUuid, target, item, label }: { branchUuid: string; target: PriceTarget; item: PricedItem; label: string }) {
    const form = useForm({
        target_type: target,
        target_uuid: item.uuid,
        price: item.branch_price ?? '',
        reason: '',
        apply_to_subscribers: target !== 'offer',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/branches/${branchUuid}/prices`, { preserveScroll: true, onSuccess: () => form.reset('reason') });
    }

    return (
        <form onSubmit={submit} className="grid gap-3 border-b border-slate-100 py-4 last:border-0 md:grid-cols-[minmax(0,1.2fr)_8rem_minmax(0,1.5fr)_auto] md:items-start">
            <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-900">{label}</p>
                <p className="text-xs text-slate-500">
                    HQ: {money(item.hq_price)}
                    {item.branch_price !== null && <span className="ml-2 font-medium text-blue-700">This branch: {money(item.branch_price)}</span>}
                </p>
            </div>
            <TextInput
                id={`price-${item.uuid}`}
                aria-label={`${label} price for this branch`}
                type="number"
                min="0"
                step="0.01"
                inputMode="decimal"
                placeholder={item.hq_price}
                value={form.data.price}
                onChange={(e) => form.setData('price', e.target.value)}
                error={form.errors.price}
            />
            <div className="flex flex-col gap-2">
                <TextInput
                    id={`reason-${item.uuid}`}
                    aria-label={`Reason for the ${label} price`}
                    placeholder="Reason (e.g. competition in town)"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    error={form.errors.reason}
                />
                {target !== 'offer' && (
                    <label className="flex items-center gap-2 text-xs text-slate-600">
                        <input
                            type="checkbox"
                            checked={form.data.apply_to_subscribers}
                            onChange={(e) => form.setData('apply_to_subscribers', e.target.checked)}
                            className="rounded border-slate-300"
                        />
                        Apply to this branch's current subscribers
                    </label>
                )}
            </div>
            <Button type="submit" variant="secondary" disabled={form.processing || form.data.reason.trim() === ''} className="rounded-lg px-4 py-2 text-sm">
                {form.processing ? 'Saving…' : 'Save'}
            </Button>
        </form>
    );
}

const CONTACT_FIELDS: { name: Overridable; label: string; type?: string; tip?: string }[] = [
    { name: 'location', label: 'Address' },
    { name: 'phone', label: 'Phone' },
    { name: 'email', label: 'Email', type: 'email' },
    { name: 'tech_number', label: 'Technician line' },
    { name: 'momo_number', label: 'MoMo number', tip: 'Customers of this branch pay into this number. It is printed on their bills.' },
    { name: 'momo_name', label: 'MoMo account name' },
];

const FEE_FIELDS: { name: Overridable; label: string }[] = [
    { name: 'reconnection_fine', label: 'Reconnection fine (FCFA)' },
    { name: 'relocation_fee', label: 'Relocation fee (FCFA)' },
    { name: 'reinstallation_fee', label: 'Re-installation fee (FCFA)' },
];

/**
 * A branch's own contact details, MoMo number and fees (branches v2 D4,
 * App\Http\Controllers\BranchSettingsController). What its bills, receipts
 * and messages print and what its staff charge; an empty field uses
 * headquarters' value, shown as the placeholder.
 */
export default function BranchSettings({ branch, headquarters, can_manage_branches, prices, price_history }: BranchSettingsProps) {
    const placeholder = (field: Overridable) => (headquarters[field] ? `HQ: ${headquarters[field]}` : 'Not set at HQ');

    return (
        <AppLayout
            title={`${branch.name} settings`}
            breadcrumbs={[
                ...(can_manage_branches ? [{ label: 'Branches', href: '/branches' }] : []),
                { label: branch.name },
                { label: 'Details & fees' },
            ]}
        >
            <Head title={`${branch.name} settings`} />

            <div className="animate-fade-up mb-6">
                <h2 className="font-display text-2xl text-slate-900">{branch.name}</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-500">
                    What this branch's bills, receipts and messages show, and the fees its staff charge. Leave a
                    field empty to use headquarters' value.
                </p>
            </div>

            <Form action={`/branches/${branch.uuid}/settings`} method="patch" options={{ preserveScroll: true }}>
                {({ errors, processing }) => (
                    <div className="grid max-w-4xl gap-6 lg:grid-cols-2">
                        <Card className="animate-fade-up">
                            <CardHeader>
                                <h3 className="text-sm font-semibold text-slate-900">Contact &amp; MoMo</h3>
                            </CardHeader>
                            <CardBody className="flex flex-col gap-4">
                                <TextInput
                                    id="code"
                                    name="code"
                                    label="Short code (e.g. KBA)"
                                    defaultValue={branch.code ?? ''}
                                    error={errors.code}
                                    maxLength={10}
                                />
                                {CONTACT_FIELDS.map((field) => (
                                    <TextInput
                                        key={field.name}
                                        id={field.name}
                                        name={field.name}
                                        type={field.type ?? 'text'}
                                        label={field.label}
                                        defaultValue={branch[field.name] ?? ''}
                                        placeholder={placeholder(field.name)}
                                        error={errors[field.name]}
                                        tip={field.tip}
                                    />
                                ))}
                            </CardBody>
                        </Card>

                        <Card className="animate-fade-up" style={{ animationDelay: '0.05s' }}>
                            <CardHeader>
                                <h3 className="text-sm font-semibold text-slate-900">Fees</h3>
                            </CardHeader>
                            <CardBody className="flex flex-col gap-4">
                                {FEE_FIELDS.map((field) => (
                                    <TextInput
                                        key={field.name}
                                        id={field.name}
                                        name={field.name}
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputMode="decimal"
                                        label={field.label}
                                        defaultValue={branch[field.name] ?? ''}
                                        placeholder={placeholder(field.name)}
                                        error={errors[field.name]}
                                    />
                                ))}
                                <p className="text-xs text-slate-500">
                                    A lower fee than headquarters' is allowed — towns with competition may charge less.
                                </p>
                                {can_manage_branches && (
                                    <SelectInput id="sends_to_hq" name="sends_to_hq" label="Sends its money to headquarters" defaultValue={branch.sends_to_hq} error={errors.sends_to_hq} tip="How often this branch hands the money it collects over to headquarters.">
                                        <option value="off">No schedule</option>
                                        <option value="weekly">Every week</option>
                                        <option value="monthly">Every month</option>
                                    </SelectInput>
                                )}
                            </CardBody>
                        </Card>

                        <div className="lg:col-span-2">
                            <Button type="submit" disabled={processing} className="rounded-lg px-5 py-2.5 text-sm font-semibold">
                                {processing && <LoadingSpinner className="text-white" />}
                                {processing ? 'Saving…' : 'Save settings'}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>

            <Card className="animate-fade-up mt-8 max-w-4xl">
                <CardHeader>
                    <div className="flex items-center gap-1">
                        <h3 className="text-sm font-semibold text-slate-900">Prices</h3>
                        <InfoTip
                            label="About branch prices"
                            text="Type a price only where this town charges differently from headquarters. Empty means headquarters' price."
                        />
                    </div>
                    <p className="mt-1 text-xs text-slate-500">
                        Headquarters' price applies unless this branch sets its own. Leave the price empty and save to go
                        back to headquarters' price. Every change needs a reason and is kept below.
                        <InfoTip
                            className="ml-1"
                            label="About the reason"
                            text="Say why the price changed, e.g. competition in town. It is kept in the price history below."
                        />
                    </p>
                </CardHeader>
                <CardBody>
                    {prices.services.flatMap((service) => [
                        <PriceRow key={service.uuid} branchUuid={branch.uuid} target="service" item={service} label={service.name} />,
                        ...service.variants.map((variant) => (
                            <PriceRow
                                key={variant.uuid}
                                branchUuid={branch.uuid}
                                target="variant"
                                item={variant}
                                label={`${service.name} — ${variant.name}`}
                            />
                        )),
                    ])}
                    {prices.offers.map((offer) => (
                        <PriceRow key={offer.uuid} branchUuid={branch.uuid} target="offer" item={offer} label={`Installation: ${offer.name}`} />
                    ))}
                </CardBody>
            </Card>

            {price_history.length > 0 && (
                <Card className="animate-fade-up mt-6 max-w-4xl p-0">
                    <Table label="Price history">
                        <TableHead>
                            <Th>When</Th>
                            <Th>Item</Th>
                            <Th>Price</Th>
                            <Th>Reason</Th>
                            <Th>By</Th>
                        </TableHead>
                        <TableBody>
                            {price_history.map((change, index) => (
                                <tr key={`${change.when}-${index}`}>
                                    <Td>{new Date(change.when).toLocaleString()}</Td>
                                    <Td>{change.item}</Td>
                                    <Td>{change.price !== null ? money(change.price) : 'Back to HQ price'}</Td>
                                    <Td>{change.reason}</Td>
                                    <Td>{change.by ?? '—'}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </AppLayout>
    );
}
