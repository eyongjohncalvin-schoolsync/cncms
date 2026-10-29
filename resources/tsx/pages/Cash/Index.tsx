import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { IconCash, IconLock, IconPlus, IconTransfer } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { DenominationCounter, denominationTotal, type DenominationCounts } from '@/components/cash/DenominationCounter';
import { formatCurrency } from '@/lib/formatCurrency';

interface Account {
    uuid: string;
    day_to_close: string | null;
    close_book: string | null;
    can_close: boolean;
    kind: 'till' | 'safe' | 'bank' | 'mobile_money';
    name: string;
    provider: string | null;
    account_number: string | null;
    holder_name: string | null;
    balance: string;
    closed_today: boolean;
    close: { expected: string; counted: string; variance: string; closed_at: string | null; reopen_reason: string | null } | null;
}

interface RemittanceRow {
    uuid: string;
    number: string;
    collector_name: string | null;
    received_by_name: string | null;
    status: 'submitted' | 'received';
    expected_cash: string;
    expected_mobile: string;
    declared_cash: string;
    received_cash: string | null;
    cash_variance: string | null;
    shortage_status: 'open' | 'repaid' | 'written_off' | null;
    submitted_at: string | null;
    received_at: string | null;
}

interface TransferRow {
    uuid: string;
    number: string;
    kind: 'handover' | 'deposit';
    from: string | null;
    to: string | null;
    to_user_name: string | null;
    sent_by_name: string | null;
    amount: string;
    slip_number: string | null;
    note: string | null;
    sent_at: string | null;
    confirmed_by: string[];
    required_confirmations: number;
    can_accept: boolean;
    can_recall: boolean;
    can_confirm: boolean;
    can_refuse_deposit: boolean;
}

interface ShortageRow {
    uuid: string;
    kind: string;
    owner_name: string | null;
    amount: string;
    note: string | null;
    created_at: string | null;
    can_repay: boolean;
    can_write_off: boolean;
}

interface Props {
    today: string;
    can: { view_all: boolean; receive: boolean; transfer: boolean; manage_accounts: boolean; reopen_day: boolean; branch_transfers: boolean };
    accounts: Account[];
    my_till: Account | null;
    transfer_targets: { uuid: string; name: string; kind: Account['kind'] }[];
    awaiting: RemittanceRow[];
    open_shortages: RemittanceRow[];
    recent: RemittanceRow[];
    in_transit: TransferRow[];
    shortages: ShortageRow[];
    deposit_confirmations: number;
    denominations: number[];
}

const KIND_LABELS: Record<Account['kind'], string> = { till: 'Till', safe: 'Safe', bank: 'Bank', mobile_money: 'Mobile money' };

/**
 * The cash book — App\Http\Controllers\CashBookController::index
 * (docs/plans/cash-controls.md, wave 4): where the company's money is now,
 * handovers waiting for the cashier, open shortages, and the cashier's own
 * till with its end-of-day count.
 */
export default function CashIndex({
    today,
    can,
    accounts,
    my_till,
    transfer_targets,
    awaiting,
    open_shortages,
    recent,
    in_transit,
    shortages,
    deposit_confirmations,
    denominations,
}: Props) {
    const [transferOpen, setTransferOpen] = useState(false);
    const [closing, setClosing] = useState<Account | null>(null);
    const [accountOpen, setAccountOpen] = useState(false);
    const total = accounts.reduce((sum, account) => sum + Number(account.balance), 0);
    // Refusals from the one-click actions (accept, confirm, settle…) — never silent.
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const refusals = transferOpen || closing ? [] : Object.values(errors ?? {});

    return (
        <AppLayout title="Cash book" breadcrumbs={[{ label: 'Cash book' }]}>
            <Head title="Cash book" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl text-slate-900">Cash book</h2>
                    <p className="mt-1 text-sm text-slate-500">Where the company&apos;s money is, and every handover — {new Date(today).toLocaleDateString()}.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {can.view_all && (
                        <Link href="/cash/alerts">
                            <Button variant="secondary">Alerts</Button>
                        </Link>
                    )}
                    {(can.view_all || can.receive) && (
                        <Link href="/cash/technician-fees">
                            <Button variant="secondary">Technician fees</Button>
                        </Link>
                    )}
                    {can.branch_transfers && (
                        <Link href="/cash/branch-transfers">
                            <Button variant="secondary">Branch transfers</Button>
                        </Link>
                    )}
                    {can.transfer && (
                        <Button variant="secondary" onClick={() => setTransferOpen(true)}>
                            <IconTransfer size={16} stroke={1.75} /> Deposit / transfer
                        </Button>
                    )}
                    {can.manage_accounts && (
                        <Button variant="secondary" onClick={() => setAccountOpen(true)}>
                            <IconPlus size={16} stroke={1.75} /> Add account
                        </Button>
                    )}
                </div>
            </div>

            {my_till && (
                <Card className="mb-6">
                    <CardBody className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">My till</div>
                            <div className="mt-1 text-2xl font-semibold text-slate-900">{formatCurrency(my_till.balance)}</div>
                            {my_till.closed_today && my_till.close && (
                                <div className="mt-1 text-sm text-slate-600">
                                    Closed today — counted {formatCurrency(my_till.close.counted)}
                                    {Number(my_till.close.variance) !== 0 && (
                                        <span className="font-medium text-red-700"> (difference {formatCurrency(my_till.close.variance)})</span>
                                    )}
                                </div>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <Link href={`/cash/accounts/${my_till.uuid}`}>
                                <Button variant="ghost">Ledger</Button>
                            </Link>
                            {my_till.day_to_close !== null && my_till.day_to_close !== today ? (
                                <Button variant="warning" onClick={() => setClosing(my_till)}>
                                    <IconLock size={16} stroke={1.75} /> Close {my_till.day_to_close} first
                                </Button>
                            ) : my_till.closed_today ? (
                                can.reopen_day && <ReopenButton account={my_till} />
                            ) : (
                                <Button onClick={() => setClosing(my_till)}>
                                    <IconLock size={16} stroke={1.75} /> Close today
                                </Button>
                            )}
                        </div>
                    </CardBody>
                </Card>
            )}

            {refusals.length > 0 && (
                <div role="alert" className="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {refusals.map((message) => (
                        <p key={message}>{message}</p>
                    ))}
                </div>
            )}

            <TransferTable rows={in_transit} />
            <ShortageTable rows={shortages} />

            <RemittanceTable title="Handovers waiting to be received" rows={awaiting} empty="No handovers waiting." />

            {can.view_all && (
                <>
                    <Card className="mb-6">
                        <CardHeader>
                            <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <IconCash size={16} stroke={1.75} /> Accounts — {formatCurrency(total)} in total
                            </h3>
                        </CardHeader>
                        {accounts.length === 0 ? (
                            <EmptyState title="No accounts yet" description="Tills appear when a cashier first receives money; add the safe, bank and wallets above." />
                        ) : (
                            <Table label="Cash accounts">
                                <TableHead>
                                    <Th>Account</Th>
                                    <Th>Kind</Th>
                                    <Th>Today</Th>
                                    <Th className="text-right">Balance</Th>
                                </TableHead>
                                <TableBody>
                                    {accounts.map((account) => (
                                        <tr key={account.uuid}>
                                            <Td>
                                                <Link href={`/cash/accounts/${account.uuid}`} className="font-medium text-blue-700 hover:underline">
                                                    {account.name}
                                                </Link>
                                                {account.account_number && <div className="text-xs text-slate-500">{account.account_number}</div>}
                                            </Td>
                                            <Td>{KIND_LABELS[account.kind]}</Td>
                                            <Td>
                                                {account.kind === 'till' &&
                                                    (account.day_to_close !== null && account.day_to_close !== today ? (
                                                        <Badge tone="red">{account.day_to_close} not closed</Badge>
                                                    ) : account.closed_today ? (
                                                        <Badge tone={account.close && Number(account.close.variance) !== 0 ? 'red' : 'green'}>Closed</Badge>
                                                    ) : (
                                                        <Badge tone="yellow">Open</Badge>
                                                    ))}
                                                {/* An absent cashier's till: a supervisor counts it (the difference is still theirs). */}
                                                {account.kind === 'till' && account.can_close && account.uuid !== my_till?.uuid && !account.closed_today && (
                                                    <button type="button" onClick={() => setClosing(account)} className="ml-2 text-xs font-medium text-blue-700 hover:underline">
                                                        Count &amp; close
                                                    </button>
                                                )}
                                            </Td>
                                            <Td className="text-right font-medium">{formatCurrency(account.balance)}</Td>
                                        </tr>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Card>

                    <RemittanceTable title="Open shortages" rows={open_shortages} empty="No open shortages." />
                    <RemittanceTable title="Recently received" rows={recent} empty="Nothing received yet." />
                </>
            )}

            {can.transfer && (
                <TransferModal
                    open={transferOpen}
                    onClose={() => setTransferOpen(false)}
                    // Only the holder moves money out of a till: one's own, plus the safe, bank and wallets.
                    sources={[
                        ...(my_till ? [{ uuid: my_till.uuid, name: my_till.name, kind: 'till' as const }] : []),
                        ...(can.view_all ? transfer_targets.filter((account) => account.kind !== 'till') : []),
                    ]}
                    targets={transfer_targets}
                    myTillUuid={my_till?.uuid ?? null}
                    depositConfirmations={deposit_confirmations}
                />
            )}
            {closing && <CloseDayModal open onClose={() => setClosing(null)} till={closing} today={today} denominations={denominations} />}
            {can.manage_accounts && <AddAccountModal open={accountOpen} onClose={() => setAccountOpen(false)} />}
        </AppLayout>
    );
}

function RemittanceTable({ title, rows, empty }: { title: string; rows: RemittanceRow[]; empty: string }) {
    return (
        <Card className="mb-6">
            <CardHeader>
                <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
            </CardHeader>
            {rows.length === 0 ? (
                <EmptyState title={empty} />
            ) : (
                <Table label={title}>
                    <TableHead>
                        <Th>Handover</Th>
                        <Th>Collector</Th>
                        <Th className="text-right">Cash expected</Th>
                        <Th className="text-right">Declared / received</Th>
                        <Th className="text-right">Difference</Th>
                    </TableHead>
                    <TableBody>
                        {rows.map((row) => (
                            <tr key={row.uuid}>
                                <Td>
                                    <Link href={`/cash/remittances/${row.uuid}`} className="font-medium text-blue-700 hover:underline">
                                        {row.number}
                                    </Link>
                                    <div className="text-xs text-slate-500">
                                        {new Date((row.received_at ?? row.submitted_at) as string).toLocaleString()}
                                    </div>
                                </Td>
                                <Td>{row.collector_name ?? '—'}</Td>
                                <Td className="text-right">{formatCurrency(row.expected_cash)}</Td>
                                <Td className="text-right">{formatCurrency(row.received_cash ?? row.declared_cash)}</Td>
                                <Td className={`text-right font-medium ${row.cash_variance !== null && Number(row.cash_variance) < 0 ? 'text-red-700' : ''}`}>
                                    {row.cash_variance !== null ? formatCurrency(row.cash_variance) : '—'}
                                </Td>
                            </tr>
                        ))}
                    </TableBody>
                </Table>
            )}
        </Card>
    );
}

/**
 * Money out of one account and in no other until someone acts (wave 3): a
 * handover waits for the person it was handed to (or its sender's recall), a
 * deposit for its confirmers.
 */
function TransferTable({ rows }: { rows: TransferRow[] }) {
    if (rows.length === 0) {
        return null;
    }

    const act = (row: TransferRow, action: string, payload: Record<string, string> = {}) =>
        router.post(`/cash/transfers/${row.uuid}/${action}`, payload, { preserveScroll: true });

    return (
        <Card className="mb-6" id="in-transit">
            <CardHeader>
                <h3 className="text-sm font-semibold text-slate-900">Money in transit</h3>
            </CardHeader>
            <Table label="Money in transit">
                <TableHead>
                    <Th>Transfer</Th>
                    <Th>From → to</Th>
                    <Th className="text-right">Amount</Th>
                    <Th>Waiting for</Th>
                    <Th />
                </TableHead>
                <TableBody>
                    {rows.map((row) => (
                        <tr key={row.uuid}>
                            <Td>
                                <span className="font-medium text-slate-900">{row.number}</span>
                                <div className="text-xs text-slate-500">
                                    {row.sent_by_name} · {row.sent_at ? new Date(row.sent_at).toLocaleString() : ''}
                                </div>
                            </Td>
                            <Td>
                                {row.from} → {row.to}
                                {row.slip_number && (
                                    <div className="text-xs text-slate-500">
                                        Slip {row.slip_number} ·{' '}
                                        <a href={`/cash/transfers/${row.uuid}/slip`} target="_blank" rel="noreferrer" className="text-blue-700 hover:underline">
                                            photo
                                        </a>
                                    </div>
                                )}
                            </Td>
                            <Td className="text-right font-medium">{formatCurrency(row.amount)}</Td>
                            <Td className="text-sm">
                                {row.kind === 'deposit'
                                    ? `${row.confirmed_by.length}/${row.required_confirmations} confirmed${row.confirmed_by.length > 0 ? ` (${row.confirmed_by.join(', ')})` : ''}`
                                    : (row.to_user_name ?? '—')}
                            </Td>
                            <Td>
                                <div className="flex flex-wrap justify-end gap-2">
                                    {row.can_accept && (
                                        <Button
                                            onClick={() => {
                                                const counted = window.prompt(`How much did you count? (${row.amount} was handed over)`, String(Number(row.amount)));
                                                if (counted) {
                                                    act(row, 'accept', { counted });
                                                }
                                            }}
                                        >
                                            Accept
                                        </Button>
                                    )}
                                    {row.can_accept && (
                                        <Button
                                            variant="secondary"
                                            onClick={() => {
                                                const reason = window.prompt('Why refuse it? It goes back to the sender.');
                                                if (reason) {
                                                    act(row, 'refuse', { reason });
                                                }
                                            }}
                                        >
                                            Refuse
                                        </Button>
                                    )}
                                    {row.can_recall && (
                                        <Button variant="secondary" onClick={() => window.confirm('Take this handover back into your till?') && act(row, 'recall')}>
                                            Take back
                                        </Button>
                                    )}
                                    {row.can_confirm && (
                                        <Button onClick={() => window.confirm(`Does the bank show ${row.amount} on slip ${row.slip_number}?`) && act(row, 'confirm')}>
                                            Confirm in bank
                                        </Button>
                                    )}
                                    {row.can_refuse_deposit && (
                                        <Button
                                            variant="secondary"
                                            onClick={() => {
                                                const found = window.prompt('How much does the bank show for this slip? (0 if nothing)', '0');
                                                if (found === null) {
                                                    return;
                                                }
                                                const reason = window.prompt('What did you see? The missing money is the depositor\'s shortage.');
                                                if (reason) {
                                                    act(row, 'refuse-deposit', { found, reason });
                                                }
                                            }}
                                        >
                                            Not in bank
                                        </Button>
                                    )}
                                </div>
                            </Td>
                        </tr>
                    ))}
                </TableBody>
            </Table>
        </Card>
    );
}

/** Money missing, owned by the person named until someone else settles it (wave 3). */
function ShortageTable({ rows }: { rows: ShortageRow[] }) {
    if (rows.length === 0) {
        return null;
    }

    const settle = (row: ShortageRow, outcome: 'repaid' | 'written_off') => {
        const note = window.prompt(outcome === 'repaid' ? 'Note (optional) — the cash goes into your till' : 'Why write it off?');
        if (note === null || (outcome === 'written_off' && note.trim() === '')) {
            return;
        }
        router.post(`/cash/shortages/${row.uuid}/settle`, { outcome, note }, { preserveScroll: true });
    };

    return (
        <Card className="mb-6" id="shortages">
            <CardHeader>
                <h3 className="text-sm font-semibold text-slate-900">Money missing — owned</h3>
            </CardHeader>
            <Table label="Owned shortages">
                <TableHead>
                    <Th>Who owes it</Th>
                    <Th>What</Th>
                    <Th className="text-right">Amount</Th>
                    <Th />
                </TableHead>
                <TableBody>
                    {rows.map((row) => (
                        <tr key={row.uuid}>
                            <Td className="font-medium">{row.owner_name ?? '—'}</Td>
                            <Td>
                                {row.kind}
                                {row.note && <div className="text-xs text-slate-500">{row.note}</div>}
                            </Td>
                            <Td className="text-right font-medium text-red-700">{formatCurrency(row.amount)}</Td>
                            <Td>
                                <div className="flex justify-end gap-2">
                                    {row.can_repay && <Button onClick={() => settle(row, 'repaid')}>Repaid</Button>}
                                    {row.can_write_off && (
                                        <Button variant="secondary" onClick={() => settle(row, 'written_off')}>
                                            Write off
                                        </Button>
                                    )}
                                </div>
                            </Td>
                        </tr>
                    ))}
                </TableBody>
            </Table>
        </Card>
    );
}

function ReopenButton({ account }: { account: Account }) {
    return (
        <Button
            variant="warning"
            onClick={() => {
                const reason = window.prompt(`Why reopen ${account.name} for today?`);
                if (reason) {
                    router.post(`/cash/accounts/${account.uuid}/reopen-day`, { reason }, { preserveScroll: true });
                }
            }}
        >
            Reopen today
        </Button>
    );
}

function TransferModal({
    open,
    onClose,
    sources,
    targets,
    myTillUuid,
    depositConfirmations,
}: {
    open: boolean;
    onClose: () => void;
    sources: Props['transfer_targets'];
    targets: Props['transfer_targets'];
    myTillUuid: string | null;
    depositConfirmations: number;
}) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        from_account: string;
        to_account: string;
        amount: string;
        reference: string;
        note: string;
        slip_photo: File | null;
    }>({ from_account: '', to_account: '', amount: '', reference: '', note: '', slip_photo: null });
    const target = targets.find((account) => account.uuid === data.to_account);
    const toBank = target?.kind === 'bank';
    const toSomeoneElse = target?.kind === 'till' && target.uuid !== myTillUuid;
    // Errors the server reports on fields the form does not have (CashTransferService, CashBookService).
    const serverErrors = errors as Partial<Record<string, string>>;

    return (
        <Modal open={open} onClose={onClose} title="Deposit or transfer">
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/cash/transfers', {
                        preserveScroll: true,
                        onSuccess: () => {
                            reset();
                            onClose();
                        },
                    });
                }}
            >
                <SelectInput label="From" value={data.from_account} onChange={(e) => setData('from_account', e.target.value)} error={errors.from_account} required>
                    <option value="">Choose…</option>
                    {sources.map((account) => (
                        <option key={account.uuid} value={account.uuid}>
                            {account.name}
                        </option>
                    ))}
                </SelectInput>
                <SelectInput label="To" value={data.to_account} onChange={(e) => setData('to_account', e.target.value)} error={errors.to_account} required>
                    <option value="">Choose…</option>
                    {targets
                        .filter((account) => account.uuid !== data.from_account)
                        .map((account) => (
                            <option key={account.uuid} value={account.uuid}>
                                {account.name}
                            </option>
                        ))}
                </SelectInput>
                <TextInput id="transfer_amount" label="Amount (FCFA)" type="number" min="0" value={data.amount} onChange={(e) => setData('amount', e.target.value)} error={errors.amount} required />
                <TextInput
                    id="transfer_reference"
                    label={toBank ? 'Bank deposit slip number' : 'Reference (optional)'}
                    value={data.reference}
                    onChange={(e) => setData('reference', e.target.value)}
                    error={errors.reference}
                    required={toBank}
                />
                {toBank && (
                    <div>
                        <label htmlFor="slip_photo" className="mb-1 block text-sm font-medium text-slate-700">
                            Photo of the stamped slip
                        </label>
                        <input
                            id="slip_photo"
                            type="file"
                            accept="image/*,application/pdf"
                            capture="environment"
                            onChange={(e) => setData('slip_photo', e.target.files?.[0] ?? null)}
                            className="block w-full text-sm"
                            required
                        />
                        {errors.slip_photo && <p className="mt-1 text-xs text-red-600">{errors.slip_photo}</p>}
                        {serverErrors.slip_number && <p className="mt-1 text-xs text-red-600">{serverErrors.slip_number}</p>}
                        <p className="mt-1 text-xs text-slate-500">
                            It leaves this account now and reaches the bank account once {depositConfirmations} other{' '}
                            {depositConfirmations === 1 ? 'person confirms' : 'people confirm'} it against the bank&apos;s record.
                        </p>
                    </div>
                )}
                {toSomeoneElse && (
                    <p className="text-xs text-slate-500">A handover: it is theirs once they accept what they count — anything less is yours to explain.</p>
                )}
                {errors.from_account && <p className="text-xs text-red-600">{errors.from_account}</p>}
                {serverErrors.account && <p className="text-xs text-red-600">{serverErrors.account}</p>}
                <TextInput id="transfer_note" label="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Record
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function CloseDayModal({
    open,
    onClose,
    till,
    today,
    denominations,
}: {
    open: boolean;
    onClose: () => void;
    till: Account;
    today: string;
    denominations: number[];
}) {
    const [counts, setCounts] = useState<DenominationCounts>({});
    const { data, setData, post, processing, errors, transform } = useForm({ counted: '', note: '' });
    const counted = denominationTotal(counts);
    const usingCounter = counted > 0;
    const total = usingCounter ? counted : Number(data.counted || 0);
    const day = till.day_to_close && till.day_to_close !== today ? till.day_to_close : 'today';

    transform((form) => ({ ...form, counted: String(total), denominations: usingCounter ? counts : null }));

    return (
        <Modal open={open} onClose={onClose} title={`Close ${till.name} for ${day}`}>
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/cash/accounts/${till.uuid}/close-day`, { preserveScroll: true, onSuccess: onClose });
                }}
            >
                <p className="text-sm text-slate-500">
                    Count the drawer. The book says it should hold <strong>{formatCurrency(till.close_book ?? till.balance)}</strong>
                    {day !== 'today' ? ` at the end of ${day}` : ''}; any shortfall is recorded as {till.holder_name ?? 'the holder'}&apos;s shortage and
                    cannot be hidden.
                </p>
                <DenominationCounter denominations={denominations} counts={counts} onChange={setCounts} />
                {!usingCounter && (
                    <TextInput id="counted" label="Or type the total counted (FCFA)" type="number" min="0" value={data.counted} onChange={(e) => setData('counted', e.target.value)} />
                )}
                {errors.counted && <p className="text-xs text-red-600">{errors.counted}</p>}
                <TextInput id="close_note" label="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Close with {formatCurrency(total)}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function AddAccountModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({ kind: 'safe', name: '', provider: '', account_number: '' });

    return (
        <Modal open={open} onClose={onClose} title="Add an account">
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/cash/accounts', {
                        preserveScroll: true,
                        onSuccess: () => {
                            reset();
                            onClose();
                        },
                    });
                }}
            >
                <SelectInput label="Kind" value={data.kind} onChange={(e) => setData('kind', e.target.value)} error={errors.kind}>
                    <option value="safe">Safe</option>
                    <option value="bank">Bank account</option>
                    <option value="mobile_money">Mobile-money wallet</option>
                </SelectInput>
                {data.kind === 'mobile_money' && (
                    <SelectInput label="Operator" value={data.provider} onChange={(e) => setData('provider', e.target.value)} error={errors.provider} required>
                        <option value="">Choose…</option>
                        <option value="mtn">MTN MoMo</option>
                        <option value="orange">Orange Money</option>
                    </SelectInput>
                )}
                <TextInput id="account_name" label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                <TextInput
                    id="account_number"
                    label="Account / wallet number (optional)"
                    value={data.account_number}
                    onChange={(e) => setData('account_number', e.target.value)}
                    error={errors.account_number}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Add
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
