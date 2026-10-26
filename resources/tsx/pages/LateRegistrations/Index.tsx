import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { IconClockExclamation } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { SelectInput } from '@/components/ui/SelectInput';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatCurrency } from '@/lib/formatCurrency';

interface Row {
    uuid: string;
    name: string;
    archived: boolean;
    branch: string;
    zone: string;
    connected_on: string | null;
    registered_on: string | null;
    days_late: number;
    connected_by: string;
    reason: string | null;
    says_paid_amount: string | null;
    says_paid_to: string | null;
    not_billed_months: number;
    not_billed_amount: string;
    charged_amount: string;
    charge_status: string | null;
}

interface Group {
    name: string;
    customers: number;
    average_days_late: number;
    not_billed_amount: string;
    charged_amount: string;
    says_paid_someone: number;
}

interface LateRegistrationsProps {
    months: number;
    rows: Row[];
    by_connector: Group[];
    by_branch: Group[];
    by_zone: Group[];
}

const groupings = [
    { key: 'by_connector', label: 'By who connected' },
    { key: 'by_branch', label: 'By branch' },
    { key: 'by_zone', label: 'By zone' },
] as const;

/**
 * Late registrations, for auditing (LateRegistrationController::index):
 * customers registered after their connection month closed — how late, who
 * connected them, what they say they paid, the months not billed and what
 * was charged — with totals per person, branch and zone.
 */
export default function LateRegistrationsIndex({ months, rows, by_connector, by_branch, by_zone }: LateRegistrationsProps) {
    const [grouping, setGrouping] = useState<(typeof groupings)[number]['key']>('by_connector');
    const groups = { by_connector, by_branch, by_zone }[grouping];
    const notBilled = rows.reduce((sum, row) => sum + Number(row.not_billed_amount), 0);
    const charged = rows.reduce((sum, row) => sum + Number(row.charged_amount), 0);
    const saysPaid = rows.filter((row) => Number(row.says_paid_amount ?? 0) > 0).length;

    return (
        <AppLayout title="Late registrations">
            <Head title="Late registrations" />

            <div className="animate-fade-up mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                        <IconClockExclamation size={20} stroke={1.75} />
                    </span>
                    <div>
                        <h1 className="font-display text-2xl text-slate-900">Late registrations</h1>
                        <p className="text-sm text-slate-500">Customers registered after the month they were connected — for auditing who connects and who records.</p>
                    </div>
                </div>
                <div className="w-48">
                    <SelectInput
                        id="months"
                        value={String(months)}
                        onChange={(e) => router.get('/late-registrations', { months: e.target.value }, { preserveScroll: true })}
                    >
                        {[3, 6, 12, 24].map((m) => (
                            <option key={m} value={m}>
                                Registered in the last {m} months
                            </option>
                        ))}
                    </SelectInput>
                </div>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
                <StatCard label="Late registrations" value={String(rows.length)} icon={<IconClockExclamation size={20} stroke={1.75} />} tone="yellow" />
                <StatCard label="Months not billed (value)" value={formatCurrency(String(notBilled))} icon={<IconClockExclamation size={20} stroke={1.75} />} tone="red" />
                <StatCard label="Charged as old debt" value={formatCurrency(String(charged))} icon={<IconClockExclamation size={20} stroke={1.75} />} tone="green" />
                <StatCard label="Customer says they paid someone" value={String(saysPaid)} icon={<IconClockExclamation size={20} stroke={1.75} />} tone="red" />
            </div>

            {rows.length === 0 ? (
                <EmptyState title="No late registrations in this period" />
            ) : (
                <>
                    <div className="mb-3 flex gap-1 border-b border-slate-200">
                        {groupings.map((option) => (
                            <button
                                key={option.key}
                                type="button"
                                onClick={() => setGrouping(option.key)}
                                className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${
                                    grouping === option.key ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700'
                                }`}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                    <Card className="mb-6">
                        <Table>
                            <TableHead>
                                <tr>
                                    <Th>Name</Th>
                                    <Th>Customers</Th>
                                    <Th>Average days late</Th>
                                    <Th>Not billed</Th>
                                    <Th>Charged</Th>
                                    <Th>Says paid someone</Th>
                                </tr>
                            </TableHead>
                            <TableBody>
                                {groups.map((group) => (
                                    <tr key={group.name}>
                                        <Td className="font-medium text-slate-900">{group.name}</Td>
                                        <Td>{group.customers}</Td>
                                        <Td>{group.average_days_late}</Td>
                                        <Td>{formatCurrency(group.not_billed_amount)}</Td>
                                        <Td>{formatCurrency(group.charged_amount)}</Td>
                                        <Td className={group.says_paid_someone > 0 ? 'font-semibold text-red-700' : ''}>{group.says_paid_someone}</Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>

                    <Card>
                        <Table>
                            <TableHead>
                                <tr>
                                    <Th>Customer</Th>
                                    <Th>Connected</Th>
                                    <Th>Registered</Th>
                                    <Th>Days late</Th>
                                    <Th>Connected by</Th>
                                    <Th>Says paid</Th>
                                    <Th>Not billed</Th>
                                    <Th>Charged</Th>
                                </tr>
                            </TableHead>
                            <TableBody>
                                {rows.map((row) => (
                                    <tr key={row.uuid}>
                                        <Td>
                                            {row.archived ? (
                                                <span>{row.name} (archived)</span>
                                            ) : (
                                                <Link href={`/customers/${row.uuid}`} className="font-medium text-blue-600 hover:underline">
                                                    {row.name}
                                                </Link>
                                            )}
                                            <div className="text-xs text-slate-500">
                                                {row.branch} · {row.zone}
                                            </div>
                                            {row.reason && <div className="text-xs text-slate-500">{row.reason}</div>}
                                        </Td>
                                        <Td>{row.connected_on}</Td>
                                        <Td>{row.registered_on}</Td>
                                        <Td>{row.days_late}</Td>
                                        <Td>{row.connected_by}</Td>
                                        <Td className={Number(row.says_paid_amount ?? 0) > 0 ? 'font-semibold text-red-700' : ''}>
                                            {row.says_paid_amount === null
                                                ? 'Not asked'
                                                : Number(row.says_paid_amount) > 0
                                                  ? `${formatCurrency(row.says_paid_amount)}${row.says_paid_to ? ` to ${row.says_paid_to}` : ''}`
                                                  : 'Nobody'}
                                        </Td>
                                        <Td>
                                            {row.not_billed_months > 0 ? `${row.not_billed_months} mo · ${formatCurrency(row.not_billed_amount)}` : '—'}
                                        </Td>
                                        <Td>{Number(row.charged_amount) > 0 ? `${formatCurrency(row.charged_amount)} (${row.charge_status?.replaceAll('_', ' ')})` : '—'}</Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                </>
            )}
        </AppLayout>
    );
}
