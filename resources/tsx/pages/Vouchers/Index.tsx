import { useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { IconPlus } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { InvestorLayout } from '@/layouts/InvestorLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { formatCurrency } from '@/lib/formatCurrency';

export type VoucherStatus = 'pending' | 'approved' | 'rejected' | 'cancelled' | 'paid' | 'settled';

export interface VoucherRow {
    uuid: string;
    number: string;
    kind: 'expense' | 'advance';
    payee_name: string;
    purpose: string;
    amount: string;
    needed_on: string | null;
    status: VoucherStatus;
    approval_tier: 'standard' | 'stakeholders';
    requested_by_name: string | null;
    created_at: string | null;
    overdue_settlement: boolean;
    awaiting_me?: boolean;
    ready_to_pay?: boolean;
}

interface Props {
    vouchers: VoucherRow[];
    can_request: boolean;
    limits: { single: string | null; daily: string | null; committed_today: string };
    categories: { uuid: string; name: string }[];
    today: string;
    is_investor: boolean;
}

export function VoucherStatusBadge({ status }: { status: VoucherStatus }) {
    const tone = { pending: 'yellow', approved: 'blue', rejected: 'red', cancelled: 'slate', paid: 'green', settled: 'green' } as const;
    const label = { pending: 'Awaiting approval', approved: 'Approved — to pay', rejected: 'Rejected', cancelled: 'Cancelled', paid: 'Paid', settled: 'Settled' };

    return <Badge tone={tone[status]}>{label[status]}</Badge>;
}

const TABS = [
    { key: 'mine', label: 'Needs me' },
    { key: 'open', label: 'Open' },
    { key: 'all', label: 'All' },
] as const;

/**
 * Vouchers / bons de caisse — App\Http\Controllers\VoucherController::index
 * (docs/plans/cash-controls.md, wave 5). Investors see it in their own
 * layout: it is where they approve payouts above the company's limits.
 */
export default function VouchersIndex({ vouchers, can_request, limits, categories, today, is_investor }: Props) {
    const [tab, setTab] = useState<(typeof TABS)[number]['key']>('mine');
    const [requestOpen, setRequestOpen] = useState(false);

    const rows = useMemo(() => {
        if (tab === 'mine') {
            return vouchers.filter((voucher) => voucher.awaiting_me || voucher.ready_to_pay);
        }

        if (tab === 'open') {
            return vouchers.filter((voucher) => ['pending', 'approved'].includes(voucher.status) || voucher.overdue_settlement);
        }

        return vouchers;
    }, [tab, vouchers]);

    const content = (
        <>
            <Head title="Vouchers" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl text-slate-900">{is_investor ? 'Payouts awaiting your approval' : 'Vouchers'}</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-500">
                        Every franc that leaves the company goes through a numbered voucher.
                        {limits.single ? ` Above ${formatCurrency(limits.single)} per voucher` : ''}
                        {limits.single && limits.daily ? ' or' : ''}
                        {limits.daily ? ` ${formatCurrency(limits.daily)} per day` : ''}
                        {limits.single || limits.daily ? ', every stakeholder must approve.' : ''}
                    </p>
                    {limits.daily && (
                        <p className="mt-1 text-xs text-slate-500">
                            Committed for today: {formatCurrency(limits.committed_today)} of {formatCurrency(limits.daily)}.
                        </p>
                    )}
                </div>
                {can_request && (
                    <Button onClick={() => setRequestOpen(true)}>
                        <IconPlus size={16} stroke={1.75} /> Request a voucher
                    </Button>
                )}
            </div>

            <div className="mb-3 inline-flex rounded-lg bg-slate-100 p-1">
                {TABS.map((option) => (
                    <button
                        key={option.key}
                        type="button"
                        onClick={() => setTab(option.key)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium ${tab === option.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                    >
                        {option.label}
                    </button>
                ))}
            </div>

            <Card>
                {rows.length === 0 ? (
                    <EmptyState title={tab === 'mine' ? 'Nothing needs you right now' : 'No vouchers'} />
                ) : (
                    <Table label="Vouchers">
                        <TableHead>
                            <Th>Voucher</Th>
                            <Th>Pay to</Th>
                            <Th>Requested by</Th>
                            <Th>Status</Th>
                            <Th className="text-right">Amount</Th>
                        </TableHead>
                        <TableBody>
                            {rows.map((voucher) => (
                                <tr key={voucher.uuid}>
                                    <Td>
                                        <Link href={`/vouchers/${voucher.uuid}`} className="font-medium text-blue-700 hover:underline">
                                            {voucher.number}
                                        </Link>
                                        <div className="text-xs text-slate-500">
                                            {voucher.kind === 'advance' ? 'Cash advance' : 'Expense'} · needed {voucher.needed_on}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div>{voucher.payee_name}</div>
                                        <div className="max-w-xs truncate text-xs text-slate-500">{voucher.purpose}</div>
                                    </Td>
                                    <Td>{voucher.requested_by_name ?? '—'}</Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            <VoucherStatusBadge status={voucher.status} />
                                            {voucher.approval_tier === 'stakeholders' && voucher.status === 'pending' && <Badge tone="red">Stakeholders</Badge>}
                                            {voucher.overdue_settlement && <Badge tone="red">Not justified</Badge>}
                                        </div>
                                    </Td>
                                    <Td className="text-right font-medium">{formatCurrency(voucher.amount)}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>

            {can_request && <RequestModal open={requestOpen} onClose={() => setRequestOpen(false)} categories={categories} today={today} limits={limits} />}
        </>
    );

    return is_investor ? (
        <InvestorLayout title="Approvals">{content}</InvestorLayout>
    ) : (
        <AppLayout title="Vouchers" breadcrumbs={[{ label: 'Vouchers' }]}>
            {content}
        </AppLayout>
    );
}

function RequestModal({
    open,
    onClose,
    categories,
    today,
    limits,
}: {
    open: boolean;
    onClose: () => void;
    categories: Props['categories'];
    today: string;
    limits: Props['limits'];
}) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        kind: 'expense' | 'advance';
        payee_name: string;
        purpose: string;
        category_uuid: string;
        amount: string;
        needed_on: string;
        attachment: File | null;
    }>({ kind: 'expense', payee_name: '', purpose: '', category_uuid: '', amount: '', needed_on: today, attachment: null });

    const amount = Number(data.amount || 0);
    const aboveSingle = limits.single !== null && amount > Number(limits.single);
    const aboveDaily = limits.daily !== null && data.needed_on === today && Number(limits.committed_today) + amount > Number(limits.daily);

    return (
        <Modal open={open} onClose={onClose} title="Request a voucher">
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/vouchers', {
                        forceFormData: true,
                        onSuccess: () => {
                            reset();
                            onClose();
                        },
                    });
                }}
            >
                <div className="inline-flex w-fit rounded-lg bg-slate-100 p-1">
                    {(['expense', 'advance'] as const).map((kind) => (
                        <button
                            key={kind}
                            type="button"
                            onClick={() => setData('kind', kind)}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium ${data.kind === kind ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'}`}
                        >
                            {kind === 'expense' ? 'Pay an expense' : 'Cash advance'}
                        </button>
                    ))}
                </div>
                {data.kind === 'advance' && (
                    <p className="-mt-2 text-xs text-slate-500">Money given before it is spent. It must be justified with receipts — and any change returned — within the company&apos;s deadline.</p>
                )}
                <TextInput id="payee_name" label="Pay to" value={data.payee_name} onChange={(e) => setData('payee_name', e.target.value)} error={errors.payee_name} required />
                <TextInput id="purpose" label="What for" value={data.purpose} onChange={(e) => setData('purpose', e.target.value)} error={errors.purpose} required />
                <SelectInput
                    label={data.kind === 'expense' ? 'Expense category' : 'Expense category (optional)'}
                    value={data.category_uuid}
                    onChange={(e) => setData('category_uuid', e.target.value)}
                    error={errors.category_uuid}
                    required={data.kind === 'expense'}
                >
                    <option value="">Choose…</option>
                    {categories.map((category) => (
                        <option key={category.uuid} value={category.uuid}>
                            {category.name}
                        </option>
                    ))}
                </SelectInput>
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextInput id="amount" label="Amount (FCFA)" type="number" min="0" value={data.amount} onChange={(e) => setData('amount', e.target.value)} error={errors.amount} required />
                    <TextInput id="needed_on" label="Needed on" type="date" min={today} value={data.needed_on} onChange={(e) => setData('needed_on', e.target.value)} error={errors.needed_on} />
                </div>
                {(aboveSingle || aboveDaily) && (
                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                        {aboveSingle ? 'This is above the single-voucher limit' : 'This takes today’s payouts above the daily limit'} — every stakeholder will have to approve it.
                    </p>
                )}
                <label className="flex flex-col gap-1.5 text-sm font-medium text-slate-700">
                    Invoice / quote (optional)
                    <input type="file" accept="image/*,application/pdf" onChange={(e) => setData('attachment', e.target.files?.[0] ?? null)} className="text-sm" />
                    {errors.attachment && <span className="text-xs text-red-600">{errors.attachment}</span>}
                </label>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Send for approval
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
