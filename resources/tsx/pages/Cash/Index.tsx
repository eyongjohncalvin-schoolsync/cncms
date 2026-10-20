import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
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

interface Props {
    today: string;
    can: { view_all: boolean; receive: boolean; transfer: boolean; manage_accounts: boolean; reopen_day: boolean };
    accounts: Account[];
    my_till: Account | null;
    transfer_targets: { uuid: string; name: string; kind: Account['kind'] }[];
    awaiting: RemittanceRow[];
    open_shortages: RemittanceRow[];
    recent: RemittanceRow[];
    denominations: number[];
}

const KIND_LABELS: Record<Account['kind'], string> = { till: 'Till', safe: 'Safe', bank: 'Bank', mobile_money: 'Mobile money' };

/**
 * The cash book — App\Http\Controllers\CashBookController::index
 * (docs/plans/cash-controls.md, wave 4): where the company's money is now,
 * handovers waiting for the cashier, open shortages, and the cashier's own
 * till with its end-of-day count.
 */
export default function CashIndex({ today, can, accounts, my_till, transfer_targets, awaiting, open_shortages, recent, denominations }: Props) {
    const [transferOpen, setTransferOpen] = useState(false);
    const [closeOpen, setCloseOpen] = useState(false);
    const [accountOpen, setAccountOpen] = useState(false);
    const total = accounts.reduce((sum, account) => sum + Number(account.balance), 0);

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
                            {my_till.closed_today ? (
                                can.reopen_day && <ReopenButton account={my_till} />
                            ) : (
                                <Button onClick={() => setCloseOpen(true)}>
                                    <IconLock size={16} stroke={1.75} /> Close today
                                </Button>
                            )}
                        </div>
                    </CardBody>
                </Card>
            )}

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
                                                    (account.closed_today ? (
                                                        <Badge tone={account.close && Number(account.close.variance) !== 0 ? 'red' : 'green'}>Closed</Badge>
                                                    ) : (
                                                        <Badge tone="yellow">Open</Badge>
                                                    ))}
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
                    sources={can.view_all ? transfer_targets : my_till ? [{ uuid: my_till.uuid, name: my_till.name, kind: 'till' }] : []}
                    targets={transfer_targets}
                />
            )}
            {my_till && <CloseDayModal open={closeOpen} onClose={() => setCloseOpen(false)} till={my_till} denominations={denominations} />}
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
}: {
    open: boolean;
    onClose: () => void;
    sources: Props['transfer_targets'];
    targets: Props['transfer_targets'];
}) {
    const { data, setData, post, processing, errors, reset } = useForm({ from_account: '', to_account: '', amount: '', reference: '', note: '' });
    const toBank = targets.find((target) => target.uuid === data.to_account)?.kind === 'bank';

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

function CloseDayModal({ open, onClose, till, denominations }: { open: boolean; onClose: () => void; till: Account; denominations: number[] }) {
    const [counts, setCounts] = useState<DenominationCounts>({});
    const { data, setData, post, processing, errors, transform } = useForm({ counted: '', note: '' });
    const counted = denominationTotal(counts);
    const usingCounter = counted > 0;
    const total = usingCounter ? counted : Number(data.counted || 0);

    transform((form) => ({ ...form, counted: String(total), denominations: usingCounter ? counts : null }));

    return (
        <Modal open={open} onClose={onClose} title={`Close ${till.name} for today`}>
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/cash/accounts/${till.uuid}/close-day`, { preserveScroll: true, onSuccess: onClose });
                }}
            >
                <p className="text-sm text-slate-500">
                    Count the drawer. The book says it should hold <strong>{formatCurrency(till.balance)}</strong>; any difference is recorded and cannot be hidden.
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
