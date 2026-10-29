import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { IconPrinter } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { InvestorLayout } from '@/layouts/InvestorLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { paymentMethodOptions } from '@/components/shared/PaymentMethodFields';
import { formatCurrency } from '@/lib/formatCurrency';
import { VoucherStatusBadge, type VoucherRow } from './Index';

interface Props {
    voucher: VoucherRow & {
        category_name: string | null;
        amount_words: { en: string; fr: string };
        tier_reasons: string[];
        approval_mode: 'all' | 'any';
        has_attachment: boolean;
        has_signed_copy: boolean;
        rejection_reason: string | null;
        paid_by_name: string | null;
        paid_at: string | null;
        paid_from_name: string | null;
        payment_method: string | null;
        payment_reference: string | null;
        received_by_name: string | null;
        settle_due_on: string | null;
        spent_amount: string | null;
        returned_amount: string | null;
        settled_by_name: string | null;
        settlement_note: string | null;
        category_uuid: string | null;
        from_collections: boolean;
        remittance: { uuid: string; number: string } | null;
        has_receipt: boolean;
        origin: 'refund' | 'return_to_collector' | 'salary' | null;
        is_locked: boolean;
        needs_signed_copy: boolean;
        salary_period: string | null;
        expenditures: { uuid: string; amount: string; spent_at: string | null; source: string; status: string }[];
        approvals: { name: string | null; decision: 'approved' | 'rejected'; note: string | null; decided_at: string | null }[];
        stakeholders: { name: string; approved: boolean }[];
    };
    can: { decide: boolean; cancel: boolean; pay: boolean; settle: boolean; correct: boolean };
    pay_accounts: { uuid: string; name: string; balance: string }[];
    categories: { uuid: string; name: string }[];
    is_investor: boolean;
}

const TIER_REASON_LABELS: Record<string, string> = {
    above_single_limit: 'Above the single-bon limit',
    above_daily_limit: 'Takes today’s payouts above the daily limit',
    above_monthly_cap: 'Takes this month’s spending above the monthly cap',
};

const when = (value: string | null) => (value ? new Date(value).toLocaleString() : '—');

/**
 * One voucher — App\Http\Controllers\VoucherController::show
 * (docs/plans/cash-controls.md, wave 5): who asked, who approved, who paid
 * from where, who received it, and the printable signed copy.
 */
export default function VoucherShow({ voucher, can, pay_accounts, categories, is_investor }: Props) {
    const content = (
        <>
            <Head title={voucher.number} />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl text-slate-900">{voucher.number}</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        {voucher.kind === 'advance' ? 'Cash advance' : 'Expense'} requested by {voucher.requested_by_name} · {when(voucher.created_at)}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <VoucherStatusBadge status={voucher.status} />
                    <a href={`/vouchers/${voucher.uuid}/pdf`}>
                        <Button variant="secondary">
                            <IconPrinter size={16} stroke={1.75} /> Print voucher
                        </Button>
                    </a>
                </div>
            </div>

            <Card className="mb-6">
                <CardBody>
                    <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-[180px_1fr]">
                        <dt className="text-slate-500">Pay to</dt>
                        <dd className="font-medium text-slate-900">{voucher.payee_name}</dd>
                        <dt className="text-slate-500">For</dt>
                        <dd className="text-slate-900">
                            {voucher.purpose}
                            {voucher.category_name ? ` (${voucher.category_name})` : ''}
                        </dd>
                        <dt className="text-slate-500">Amount</dt>
                        <dd>
                            <div className="text-2xl font-semibold text-slate-900">{formatCurrency(voucher.amount)}</div>
                            <div className="text-xs italic text-slate-500">
                                {voucher.amount_words.en} — {voucher.amount_words.fr}
                            </div>
                        </dd>
                        <dt className="text-slate-500">Needed on</dt>
                        <dd className="text-slate-900">{voucher.needed_on}</dd>
                        {voucher.from_collections && (
                            <>
                                <dt className="text-slate-500">Paid from</dt>
                                <dd className="text-slate-900">
                                    The requester&apos;s own collections — settled at their cash handover
                                    {voucher.remittance && (
                                        <>
                                            {' '}
                                            (
                                            <Link href={`/cash/remittances/${voucher.remittance.uuid}`} className="text-blue-700 hover:underline">
                                                {voucher.remittance.number}
                                            </Link>
                                            )
                                        </>
                                    )}
                                </dd>
                            </>
                        )}
                        {voucher.has_receipt && (
                            <>
                                <dt className="text-slate-500">Receipt photo</dt>
                                <dd>
                                    <a href={`/vouchers/${voucher.uuid}/files/receipt`} className="font-medium text-blue-700 hover:underline">
                                        Open
                                    </a>
                                </dd>
                            </>
                        )}
                        {voucher.expenditures.length > 0 && (
                            <>
                                <dt className="text-slate-500">Expenditures</dt>
                                <dd className="flex flex-col gap-1">
                                    {voucher.expenditures.map((row) => (
                                        <Link key={row.uuid} href={`/resources/expenditures/${row.uuid}`} className="text-blue-700 hover:underline">
                                            {row.spent_at} · {formatCurrency(row.amount)} {row.source === 'adjustment' ? '(correction)' : ''}
                                            {row.status === 'void' ? ' — void' : ''}
                                        </Link>
                                    ))}
                                </dd>
                            </>
                        )}
                        {voucher.has_attachment && (
                            <>
                                <dt className="text-slate-500">Invoice / quote</dt>
                                <dd>
                                    <a href={`/vouchers/${voucher.uuid}/files/attachment`} className="font-medium text-blue-700 hover:underline">
                                        Open
                                    </a>
                                </dd>
                            </>
                        )}
                    </dl>
                </CardBody>
            </Card>

            <Card className="mb-6">
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">Approval</h3>
                </CardHeader>
                <CardBody className="flex flex-col gap-3 text-sm">
                    {voucher.approval_tier === 'stakeholders' ? (
                        <div className="rounded-lg bg-amber-50 p-3 text-amber-800">
                            {voucher.tier_reasons.map((reason) => TIER_REASON_LABELS[reason] ?? reason).join('; ') || 'Above the company’s limits'} —{' '}
                            {voucher.approval_mode === 'all' ? 'every stakeholder must approve.' : 'the owner must approve.'}
                            {voucher.stakeholders.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {voucher.stakeholders.map((stakeholder) => (
                                        <Badge key={stakeholder.name} tone={stakeholder.approved ? 'green' : 'yellow'}>
                                            {stakeholder.name}: {stakeholder.approved ? 'approved' : 'waiting'}
                                        </Badge>
                                    ))}
                                </div>
                            )}
                        </div>
                    ) : (
                        <p className="text-slate-500">Within the company&apos;s limits — one manager approval.</p>
                    )}
                    {voucher.approvals.map((approval, index) => (
                        <div key={index} className="flex flex-wrap items-center gap-2">
                            <Badge tone={approval.decision === 'approved' ? 'green' : 'red'}>{approval.decision}</Badge>
                            <span className="font-medium text-slate-900">{approval.name}</span>
                            <span className="text-slate-500">{when(approval.decided_at)}</span>
                            {approval.note && <span className="text-slate-600">— {approval.note}</span>}
                        </div>
                    ))}
                    {can.decide && <DecisionForm uuid={voucher.uuid} locked={voucher.is_locked} />}
                    {can.cancel && (
                        <div>
                            <Button variant="ghost" onClick={() => router.post(`/vouchers/${voucher.uuid}/cancel`, {}, { preserveScroll: true })}>
                                Cancel my request
                            </Button>
                        </div>
                    )}
                </CardBody>
            </Card>

            {(voucher.paid_at || can.pay) && (
                <Card className="mb-6">
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Payment</h3>
                    </CardHeader>
                    <CardBody className="text-sm">
                        {voucher.paid_at ? (
                            <div className="flex flex-col gap-1 text-slate-700">
                                <div>
                                    Paid by <strong>{voucher.paid_by_name}</strong> from {voucher.paid_from_name} · {when(voucher.paid_at)}
                                </div>
                                <div>
                                    Received by <strong>{voucher.received_by_name}</strong> · {voucher.payment_method}
                                    {voucher.payment_reference ? ` (${voucher.payment_reference})` : ''}
                                </div>
                                <div>
                                    Signed copy:{' '}
                                    {voucher.has_signed_copy ? (
                                        <a href={`/vouchers/${voucher.uuid}/files/signed-copy`} className="font-medium text-blue-700 hover:underline">
                                            Open
                                        </a>
                                    ) : (
                                        <span className="font-medium text-red-700">missing — print, have it signed, upload a photo</span>
                                    )}
                                </div>
                                {!voucher.has_signed_copy && <SignedCopyForm uuid={voucher.uuid} />}
                            </div>
                        ) : (
                            <PayForm uuid={voucher.uuid} accounts={pay_accounts} amount={voucher.amount} payee={voucher.payee_name} needsSignedCopy={voucher.needs_signed_copy} />
                        )}
                    </CardBody>
                </Card>
            )}

            {voucher.kind === 'advance' && (voucher.status === 'paid' || voucher.status === 'settled') && (
                <Card className="mb-6">
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Justification</h3>
                    </CardHeader>
                    <CardBody className="text-sm">
                        {voucher.status === 'settled' ? (
                            <p className="text-slate-700">
                                Spent {formatCurrency(voucher.spent_amount ?? 0)}, returned {formatCurrency(voucher.returned_amount ?? 0)} — settled by {voucher.settled_by_name}.
                                {voucher.settlement_note ? ` ${voucher.settlement_note}` : ''}
                            </p>
                        ) : (
                            <>
                                <p className={`mb-3 ${voucher.overdue_settlement ? 'font-medium text-red-700' : 'text-slate-600'}`}>
                                    Must be justified by {voucher.settle_due_on}
                                    {voucher.overdue_settlement ? ' — overdue.' : '.'}
                                </p>
                                {can.settle && <SettleForm uuid={voucher.uuid} amount={voucher.amount} needsCategory={!voucher.category_uuid} categories={categories} />}
                            </>
                        )}
                    </CardBody>
                </Card>
            )}

            {can.correct && (
                <Card className="mb-6" id="correct">
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Correct this bon</h3>
                    </CardHeader>
                    <CardBody>
                        <CorrectForm uuid={voucher.uuid} />
                    </CardBody>
                </Card>
            )}

            {voucher.rejection_reason && (
                <Card>
                    <CardBody className="text-sm text-red-800">Rejected: {voucher.rejection_reason}</CardBody>
                </Card>
            )}
        </>
    );

    return is_investor ? (
        <InvestorLayout title={voucher.number}>{content}</InvestorLayout>
    ) : (
        <AppLayout title={voucher.number} breadcrumbs={[{ label: 'Vouchers', href: '/vouchers' }, { label: voucher.number }]}>
            {content}
        </AppLayout>
    );
}

function DecisionForm({ uuid, locked }: { uuid: string; locked: boolean }) {
    const [note, setNote] = useState('');

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-slate-200 p-3">
            {locked ? (
                <p className="text-xs text-slate-600">This bon pays back money already taken off a payment — it can only be approved and paid, never rejected.</p>
            ) : (
                <TextInput id="decision_note" label="Note (required to reject)" value={note} onChange={(e) => setNote(e.target.value)} />
            )}
            <div className="flex gap-2">
                <Button onClick={() => router.post(`/vouchers/${uuid}/approve`, { note }, { preserveScroll: true })}>Approve</Button>
                {!locked && (
                    <Button variant="danger" disabled={note.trim() === ''} onClick={() => router.post(`/vouchers/${uuid}/reject`, { reason: note }, { preserveScroll: true })}>
                        Reject
                    </Button>
                )}
            </div>
        </div>
    );
}

function PayForm({
    uuid,
    accounts,
    amount,
    payee,
    needsSignedCopy,
}: {
    uuid: string;
    accounts: Props['pay_accounts'];
    amount: string;
    payee: string;
    needsSignedCopy: boolean;
}) {
    const { data, setData, post, processing, errors } = useForm<{ account: string; method: string; reference: string; received_by_name: string; signed_copy: File | null }>({
        account: accounts[0]?.uuid ?? '',
        method: 'cash',
        reference: '',
        received_by_name: payee,
        signed_copy: null,
    });

    return (
        <form
            className="flex flex-col gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                post(`/vouchers/${uuid}/pay`, { preserveScroll: true, forceFormData: true });
            }}
        >
            <SelectInput label="Pay from" value={data.account} onChange={(e) => setData('account', e.target.value)} error={errors.account}>
                {accounts.map((account) => (
                    <option key={account.uuid} value={account.uuid}>
                        {account.name} — holds {formatCurrency(account.balance)}
                    </option>
                ))}
            </SelectInput>
            <SelectInput label="How" value={data.method} onChange={(e) => setData('method', e.target.value)}>
                {paymentMethodOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </SelectInput>
            {data.method !== 'cash' && (
                <TextInput id="pay_reference" label="Transaction / transfer ID" value={data.reference} onChange={(e) => setData('reference', e.target.value)} error={errors.reference} required />
            )}
            <TextInput
                id="received_by_name"
                label="Received by (name of the person taking the money)"
                value={data.received_by_name}
                onChange={(e) => setData('received_by_name', e.target.value)}
                error={errors.received_by_name}
                required
            />
            <label className="flex flex-col gap-1.5 text-sm font-medium text-slate-700">
                {needsSignedCopy ? 'Photo of the voucher signed by the person paid' : 'Signed voucher photo (optional now — can be added after printing)'}
                <input
                    type="file"
                    accept="image/*,application/pdf"
                    onChange={(e) => setData('signed_copy', e.target.files?.[0] ?? null)}
                    className="text-sm"
                    required={needsSignedCopy}
                />
                {errors.signed_copy && <span className="text-xs text-red-600">{errors.signed_copy}</span>}
            </label>
            <Button type="submit" disabled={processing}>
                Pay {formatCurrency(amount)}
            </Button>
        </form>
    );
}

function SignedCopyForm({ uuid }: { uuid: string }) {
    const { setData, post, processing, errors } = useForm<{ signed_copy: File | null }>({ signed_copy: null });

    return (
        <form
            className="mt-2 flex flex-wrap items-center gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                post(`/vouchers/${uuid}/signed-copy`, { preserveScroll: true, forceFormData: true });
            }}
        >
            <input type="file" accept="image/*,application/pdf" onChange={(e) => setData('signed_copy', e.target.files?.[0] ?? null)} className="text-sm" />
            <Button type="submit" variant="secondary" disabled={processing}>
                Upload signed copy
            </Button>
            {errors.signed_copy && <span className="text-xs text-red-600">{errors.signed_copy}</span>}
        </form>
    );
}

/**
 * Correct a paid bon (docs/plans/expenditures-and-spending-controls.md,
 * item 9) — nothing in the past changes: money returned goes back into the
 * till with a negative entry this month; an underpayment raises a new bon.
 */
function CorrectForm({ uuid }: { uuid: string }) {
    const { data, setData, post, processing, errors, reset } = useForm<{ outcome: 'returned' | 'underpaid'; amount: string; reason: string }>({ outcome: 'returned', amount: '', reason: '' });

    return (
        <form
            className="flex flex-col gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                post(`/vouchers/${uuid}/correct`, { preserveScroll: true, onSuccess: () => reset() });
            }}
        >
            <SelectInput id="outcome" label="What happened" value={data.outcome} onChange={(e) => setData('outcome', e.target.value as 'returned' | 'underpaid')}>
                <option value="returned">Money came back — it goes into my till</option>
                <option value="underpaid">The payee is owed more — raise a new bon</option>
            </SelectInput>
            <div className="grid gap-3 sm:grid-cols-2">
                <TextInput id="correct_amount" label="Amount (FCFA)" type="number" min="0" value={data.amount} onChange={(e) => setData('amount', e.target.value)} error={errors.amount} required />
                <TextInput id="correct_reason" label="Reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} error={errors.reason} required />
            </div>
            <Button type="submit" variant="warning" disabled={processing}>
                Record the correction
            </Button>
        </form>
    );
}

function SettleForm({ uuid, amount, needsCategory, categories }: { uuid: string; amount: string; needsCategory: boolean; categories: { uuid: string; name: string }[] }) {
    const { data, setData, post, processing, errors } = useForm<{ spent_amount: string; returned_amount: string; note: string; category_uuid: string; receipt: File | null }>({
        spent_amount: '',
        returned_amount: '',
        note: '',
        category_uuid: '',
        receipt: null,
    });
    const total = Number(data.spent_amount || 0) + Number(data.returned_amount || 0);
    const gap = Number(amount) - total;

    return (
        <form
            className="flex flex-col gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                post(`/vouchers/${uuid}/settle`, { preserveScroll: true, forceFormData: true });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <TextInput id="spent_amount" label="Spent (receipts in hand)" type="number" min="0" value={data.spent_amount} onChange={(e) => setData('spent_amount', e.target.value)} error={errors.spent_amount} required />
                <TextInput id="returned_amount" label="Change returned to the till" type="number" min="0" value={data.returned_amount} onChange={(e) => setData('returned_amount', e.target.value)} required />
            </div>
            <p className={`text-xs ${gap === 0 ? 'text-green-700' : 'text-amber-700'}`}>
                {formatCurrency(total)} of {formatCurrency(amount)} accounted for.
                {gap > 0 && ` The ${formatCurrency(gap)} not accounted for becomes the requester's shortage.`}
            </p>
            {Number(data.spent_amount || 0) > 0 && (
                <label className="flex flex-col gap-1.5 text-sm font-medium text-slate-700">
                    Photo of the receipts
                    <input
                        type="file"
                        accept="image/*,application/pdf"
                        capture="environment"
                        onChange={(e) => setData('receipt', e.target.files?.[0] ?? null)}
                        className="text-sm"
                        required
                    />
                    {errors.receipt && <span className="text-xs text-red-600">{errors.receipt}</span>}
                </label>
            )}
            {needsCategory && (
                <SelectInput id="settle_category" label="Expense category of what was spent" value={data.category_uuid} onChange={(e) => setData('category_uuid', e.target.value)} error={errors.category_uuid}>
                    <option value="">Choose…</option>
                    {categories.map((category) => (
                        <option key={category.uuid} value={category.uuid}>
                            {category.name}
                        </option>
                    ))}
                </SelectInput>
            )}
            <TextInput id="settle_note" label="Note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
            <Button type="submit" disabled={processing}>
                Settle the advance
            </Button>
        </form>
    );
}
