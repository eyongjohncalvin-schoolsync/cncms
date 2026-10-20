import { Head, useForm } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { formatCurrency } from '@/lib/formatCurrency';

interface Movement {
    uuid: string;
    business_date: string | null;
    created_at: string | null;
    direction: 'in' | 'out';
    amount: string;
    kind: string;
    document_number: string | null;
    reference: string | null;
    counterpart_name: string | null;
    recorded_by_name: string | null;
    note: string | null;
    balance_after: string;
}

interface Props {
    account: { uuid: string; name: string; kind: string; account_number: string | null; balance: string; has_movements: boolean };
    movements: Movement[];
    can_set_opening_balance: boolean;
}

const KIND_LABELS: Record<string, string> = {
    remittance_cash: 'Cash handover',
    remittance_wallet: 'Mobile-money collection',
    deposit: 'Bank deposit',
    transfer: 'Transfer',
    voucher_payout: 'Voucher paid',
    advance_return: 'Advance returned',
    shortage_repayment: 'Shortage repaid',
    close_adjustment: 'Day-close difference',
    opening_balance: 'Opening balance',
};

/**
 * One account's ledger — App\Http\Controllers\CashBookController::show
 * (docs/plans/cash-controls.md, wave 4). Newest first, with the balance
 * after each line. Lines are never edited; corrections appear as new lines.
 */
export default function CashAccountLedger({ account, movements, can_set_opening_balance }: Props) {
    const { data, setData, post, processing, errors } = useForm({ amount: '' });

    return (
        <AppLayout title={account.name} breadcrumbs={[{ label: 'Cash book', href: '/cash' }, { label: account.name }]}>
            <Head title={account.name} />

            <div className="mb-6">
                <h2 className="font-display text-2xl text-slate-900">{account.name}</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Balance <strong className="text-slate-900">{formatCurrency(account.balance)}</strong>
                    {account.account_number ? ` · ${account.account_number}` : ''}
                </p>
            </div>

            {can_set_opening_balance && (
                <Card className="mb-6">
                    <CardBody>
                        <form
                            className="flex flex-col gap-2 sm:flex-row sm:items-end"
                            onSubmit={(e) => {
                                e.preventDefault();
                                post(`/cash/accounts/${account.uuid}/opening-balance`);
                            }}
                        >
                            <div className="flex-1">
                                <TextInput
                                    id="opening_amount"
                                    label="Money already in this account when cash controls started (FCFA)"
                                    type="number"
                                    min="0"
                                    value={data.amount}
                                    onChange={(e) => setData('amount', e.target.value)}
                                    error={errors.amount}
                                    required
                                />
                            </div>
                            <Button type="submit" disabled={processing}>
                                Set opening balance
                            </Button>
                        </form>
                    </CardBody>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">Movements</h3>
                </CardHeader>
                {movements.length === 0 ? (
                    <EmptyState title="No movements yet" />
                ) : (
                    <Table label="Account movements">
                        <TableHead>
                            <Th>When</Th>
                            <Th>What</Th>
                            <Th>By</Th>
                            <Th className="text-right">In</Th>
                            <Th className="text-right">Out</Th>
                            <Th className="text-right">Balance</Th>
                        </TableHead>
                        <TableBody>
                            {movements.map((movement) => (
                                <tr key={movement.uuid}>
                                    <Td>
                                        <div>{movement.business_date}</div>
                                        <div className="text-xs text-slate-500">{movement.created_at ? new Date(movement.created_at).toLocaleTimeString() : ''}</div>
                                    </Td>
                                    <Td>
                                        <div className="font-medium text-slate-900">{KIND_LABELS[movement.kind] ?? movement.kind}</div>
                                        <div className="text-xs text-slate-500">
                                            {[movement.document_number, movement.counterpart_name, movement.reference, movement.note].filter(Boolean).join(' · ')}
                                        </div>
                                    </Td>
                                    <Td>{movement.recorded_by_name ?? '—'}</Td>
                                    <Td className="text-right text-green-700">{movement.direction === 'in' ? formatCurrency(movement.amount) : ''}</Td>
                                    <Td className="text-right text-red-700">{movement.direction === 'out' ? formatCurrency(movement.amount) : ''}</Td>
                                    <Td className="text-right font-medium">{formatCurrency(movement.balance_after)}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>
        </AppLayout>
    );
}
