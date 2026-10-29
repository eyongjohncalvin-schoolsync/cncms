import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { Table, TableBody, TableHead, Td, Th } from '@/components/ui/Table';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';

interface AccountOption {
    uuid: string;
    name: string;
    branch_uuid: string | null;
    balance: string;
}

interface BranchOption {
    uuid: string;
    name: string;
    is_headquarters: boolean;
}

interface TransferRow {
    uuid: string;
    number: string;
    from: string | null;
    to: string | null;
    to_branch_uuid: string | null;
    from_account: string | null;
    to_account: string | null;
    amount: string;
    received_amount: string | null;
    status: 'sent' | 'received' | 'rejected';
    note: string | null;
    receive_note: string | null;
    sent_by: string | null;
    sent_at: string | null;
    received_by: string | null;
    received_at: string | null;
    can_receive: boolean;
}

const STATUS: Record<TransferRow['status'], { label: string; tone: 'yellow' | 'green' | 'red' }> = {
    sent: { label: 'In transit', tone: 'yellow' },
    received: { label: 'Received', tone: 'green' },
    rejected: { label: 'Refused — returned', tone: 'red' },
};

/**
 * Money between branches (branches v2 §4.7, App\Http\Controllers\
 * BranchTransferController): there is no joint account, so a branch sends
 * money with a transfer; it is in transit until the receiving branch puts it
 * into one of its accounts, or refuses it (it then goes back to the sender).
 */
export default function BranchTransfers({
    my_branch_uuid,
    accounts,
    branches,
    transfers,
}: {
    my_branch_uuid: string | null;
    accounts: AccountOption[];
    branches: BranchOption[];
    transfers: TransferRow[];
}) {
    const [receiving, setReceiving] = useState<TransferRow | null>(null);
    const headquarters = branches.find((branch) => branch.is_headquarters);
    const targets = branches.filter((branch) => branch.uuid !== my_branch_uuid);

    function refuse(transfer: TransferRow) {
        const reason = window.prompt(`Why is ${transfer.number} refused? The money goes back to ${transfer.from}.`);

        if (reason && reason.trim() !== '') {
            router.post(`/cash/branch-transfers/${transfer.uuid}/reject`, { reason }, { preserveScroll: true });
        }
    }

    return (
        <AppLayout title="Branch transfers" breadcrumbs={[{ label: 'Cash book', href: '/cash' }, { label: 'Branch transfers' }]}>
            <Head title="Branch transfers" />

            <div className="mb-6">
                <h2 className="font-display text-2xl text-slate-900">Branch transfers</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-500">
                    Send money to another branch — usually headquarters. It leaves your account at once and stays in transit
                    until the other branch receives it.
                </p>
            </div>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <Card className="p-0">
                    {transfers.length === 0 ? (
                        <CardBody>
                            <EmptyState title="No transfers yet" description="Money sent between branches appears here." />
                        </CardBody>
                    ) : (
                        <Table label="Branch transfers">
                            <TableHead>
                                <Th>Transfer</Th>
                                <Th>From → To</Th>
                                <Th>Amount</Th>
                                <Th>Status</Th>
                                <Th>Actions</Th>
                            </TableHead>
                            <TableBody>
                                {transfers.map((transfer) => (
                                    <tr key={transfer.uuid}>
                                        <Td>
                                            <span className="font-medium text-slate-900">{transfer.number}</span>
                                            <span className="block text-xs text-slate-500">
                                                {transfer.sent_at ? new Date(transfer.sent_at).toLocaleDateString() : ''} · {transfer.sent_by ?? '—'}
                                            </span>
                                        </Td>
                                        <Td>
                                            {transfer.from} → {transfer.to}
                                            {transfer.note && <span className="block text-xs text-slate-500">{transfer.note}</span>}
                                            {transfer.receive_note && <span className="block text-xs text-slate-500">{transfer.receive_note}</span>}
                                        </Td>
                                        <Td>
                                            {formatCurrency(transfer.amount)}
                                            {transfer.received_amount !== null && transfer.received_amount !== transfer.amount && (
                                                <span className="block text-xs font-medium text-red-700">counted {formatCurrency(transfer.received_amount)}</span>
                                            )}
                                        </Td>
                                        <Td>
                                            <Badge tone={STATUS[transfer.status].tone}>{STATUS[transfer.status].label}</Badge>
                                            {transfer.to_account && <span className="block text-xs text-slate-500">into {transfer.to_account}</span>}
                                        </Td>
                                        <Td>
                                            {transfer.can_receive && (
                                                <div className="flex gap-2">
                                                    <Button type="button" onClick={() => setReceiving(transfer)} className="px-3 py-1.5 text-sm">
                                                        Receive
                                                    </Button>
                                                    <Button type="button" variant="secondary" onClick={() => refuse(transfer)} className="px-3 py-1.5 text-sm">
                                                        Refuse
                                                    </Button>
                                                </div>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Card>

                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Send money</h3>
                    </CardHeader>
                    <CardBody>
                        {accounts.length === 0 || targets.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                Nothing to send from yet. <Link href="/cash" className="text-blue-600">Back to the cash book</Link>.
                            </p>
                        ) : (
                            <Form action="/cash/branch-transfers" method="post" resetOnSuccess options={{ preserveScroll: true }} className="flex flex-col gap-4">
                                {({ errors, processing }) => (
                                    <>
                                        <SelectInput id="from_account" name="from_account" label="From account" error={errors.from_account} required defaultValue="">
                                            <option value="" disabled>
                                                Choose an account
                                            </option>
                                            {accounts.map((account) => (
                                                <option key={account.uuid} value={account.uuid}>
                                                    {account.name} — {formatCurrency(account.balance)}
                                                </option>
                                            ))}
                                        </SelectInput>
                                        <SelectInput
                                            id="to_branch"
                                            name="to_branch"
                                            label="To branch"
                                            error={errors.to_branch}
                                            required
                                            defaultValue={headquarters && headquarters.uuid !== my_branch_uuid ? headquarters.uuid : ''}
                                        >
                                            <option value="" disabled>
                                                Choose a branch
                                            </option>
                                            {targets.map((branch) => (
                                                <option key={branch.uuid} value={branch.uuid}>
                                                    {branch.name}
                                                    {branch.is_headquarters ? ' (HQ)' : ''}
                                                </option>
                                            ))}
                                        </SelectInput>
                                        <TextInput id="amount" name="amount" type="number" min="1" step="1" inputMode="decimal" label="Amount (FCFA)" error={errors.amount} required />
                                        <TextInput id="note" name="note" label="Note (optional)" placeholder="e.g. Week 43 collections" error={errors.note} />
                                        <Button type="submit" disabled={processing}>
                                            {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                            Send
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </CardBody>
                </Card>
            </div>

            <Modal open={receiving !== null} onClose={() => setReceiving(null)} title="Receive transfer">
                {receiving && (
                    <Form
                        key={receiving.uuid}
                        action={`/cash/branch-transfers/${receiving.uuid}/receive`}
                        method="post"
                        options={{ preserveScroll: true }}
                        onSuccess={() => setReceiving(null)}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <p className="text-sm text-slate-600">
                                    {receiving.number}: {formatCurrency(receiving.amount)} from {receiving.from}. Which of your accounts did it go into?
                                </p>
                                <SelectInput id="account" name="account" label="Into account" error={errors.account} required defaultValue="">
                                    <option value="" disabled>
                                        Choose an account
                                    </option>
                                    {accounts
                                        .filter((account) => account.branch_uuid === receiving.to_branch_uuid)
                                        .map((account) => (
                                            <option key={account.uuid} value={account.uuid}>
                                                {account.name}
                                            </option>
                                        ))}
                                </SelectInput>
                                <TextInput
                                    id="received_amount"
                                    name="received_amount"
                                    type="number"
                                    min="1"
                                    step="1"
                                    inputMode="decimal"
                                    label="Amount you counted (FCFA)"
                                    defaultValue={receiving.amount}
                                    error={errors.received_amount}
                                    required
                                />
                                <p className="-mt-2 text-xs text-slate-500">Less than was sent is the sender&apos;s shortage.</p>
                                <TextInput id="receive-note" name="note" label="Note (optional)" error={errors.note} />
                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="secondary" onClick={() => setReceiving(null)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                        Receive
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </Modal>
        </AppLayout>
    );
}
