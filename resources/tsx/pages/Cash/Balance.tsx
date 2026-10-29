import { Head, Link } from '@inertiajs/react';
import { IconAlertTriangle, IconChevronLeft, IconChevronRight, IconCircleCheck } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Table, TableBody, TableHead, Td, Th } from '@/components/ui/Table';
import { formatCurrency } from '@/lib/formatCurrency';
import { Rate, ZoneTrend, type ZonePerformance } from '@/components/cash/ZonePerformance';

type Stage = 'handed_in' | 'not_confirmed' | 'written_off' | 'waiting' | 'with_collector' | 'before_controls';

interface CollectionRow extends Record<Stage, string> {
    method: string;
    label: string;
    recorded: string;
    count: number;
}

interface AccountRow {
    uuid: string;
    name: string;
    kind: 'till' | 'safe' | 'bank' | 'mobile_money';
    holder_name: string | null;
    is_active: boolean;
    opening: string;
    in: string;
    out: string;
    closing: string;
    days_closed: number;
    days_not_closed: string[];
    close_variance: string;
    last_close: { date: string; counted: string; variance: string } | null;
    statement: { book: string; statement: string; difference: string } | null;
    needs_statement: boolean;
}

interface Check {
    key: string;
    status: 'ok' | 'warning' | 'danger';
    title: string;
    detail: string;
}

interface Props {
    period: string;
    is_current: boolean;
    previous_period: string;
    next_period: string | null;
    collections: { rows: CollectionRow[]; totals: Record<Stage | 'recorded', string> };
    handovers: { count: number; at_desk_count: number; expected: string; received: string; bons: string; short: string; over: string; short_open: string; into_tills: string };
    accounts: AccountRow[];
    movements_by_kind: { kind: string; label: string; internal: boolean; in: string; out: string }[];
    money_out: {
        bons: { purpose: string; label: string; count: number; total: string }[];
        bons_total: string;
        advances_unsettled: string;
        advances_overdue: number;
        technician_fees: string;
        refunds_unpaid: { payment_uuid: string; customer_name: string | null; amount: string; bon_number: string | null; bon_uuid: string | null; bon_status: string | null }[];
    };
    shortages: { rows: { kind: string; label: string; count: number; open: string; repaid: string; written_off: string }[]; open: string; repaid: string; written_off: string };
    in_transit: { handovers: string; deposits: string; branches: string; count: number };
    zones: ZonePerformance[];
    checks: Check[];
}

const STAGES: { key: Stage; label: string }[] = [
    { key: 'handed_in', label: 'Handed in' },
    { key: 'waiting', label: 'Handover waiting' },
    { key: 'with_collector', label: 'With collector' },
    { key: 'not_confirmed', label: 'Not on statement' },
    { key: 'written_off', label: 'Written off' },
    { key: 'before_controls', label: 'Before controls' },
];

const KIND_LABELS: Record<AccountRow['kind'], string> = { till: 'Till', safe: 'Safe', bank: 'Bank', mobile_money: 'Mobile money' };

const monthLabel = (period: string) => new Date(`${period}-01T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

const isZero = (amount: string) => Number(amount) === 0;

/**
 * Money balance — App\Http\Controllers\MoneyBalanceController (owner,
 * 2026-10-28, wave 5). One branch's month on one page: the checks that
 * prove every franc adds up, then where the collections went, each
 * account's month, what went out, what is missing, and each zone's
 * collections against its bills. The branch is the switcher's (or the
 * user's fenced branch).
 */
export default function MoneyBalance({
    period,
    is_current,
    previous_period,
    next_period,
    collections,
    handovers,
    accounts,
    movements_by_kind,
    money_out,
    shortages,
    in_transit,
    zones,
    checks,
}: Props) {
    const failing = checks.filter((check) => check.status !== 'ok').length;
    // Only the stages that hold money this month get a column.
    const stages = STAGES.filter((stage) => !isZero(collections.totals[stage.key]));

    return (
        <AppLayout title="Money balance" breadcrumbs={[{ label: 'Cash book', href: '/cash' }, { label: 'Money balance' }]}>
            <Head title="Money balance" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl text-slate-900">Money balance — {monthLabel(period)}</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Every franc collected, held, moved and spent this month, and whether it adds up.
                        {is_current && ' The month is still running — figures move as the days go by.'}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Link href={`/cash/balance?period=${previous_period}`} preserveScroll>
                        <Button variant="secondary">
                            <IconChevronLeft size={16} stroke={1.75} /> {monthLabel(previous_period)}
                        </Button>
                    </Link>
                    {next_period && (
                        <Link href={`/cash/balance?period=${next_period}`} preserveScroll>
                            <Button variant="secondary">
                                {monthLabel(next_period)} <IconChevronRight size={16} stroke={1.75} />
                            </Button>
                        </Link>
                    )}
                </div>
            </div>

            <Card className="mb-6">
                <CardHeader>
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-sm font-semibold text-slate-900">Does it balance?</h3>
                        {failing === 0 ? <Badge tone="green">Everything adds up</Badge> : <Badge tone="red">{failing} to look at</Badge>}
                    </div>
                </CardHeader>
                <ul className="divide-y divide-slate-100">
                    {checks.map((check) => (
                        <li key={check.key} className="flex items-start gap-3 px-5 py-3 text-sm">
                            {check.status === 'ok' ? (
                                <IconCircleCheck size={20} stroke={1.75} className="mt-0.5 shrink-0 text-green-600" />
                            ) : (
                                <IconAlertTriangle size={20} stroke={1.75} className={`mt-0.5 shrink-0 ${check.status === 'danger' ? 'text-red-600' : 'text-amber-500'}`} />
                            )}
                            <div>
                                <div className="font-medium text-slate-900">{check.title}</div>
                                <div className="text-xs text-slate-500">{check.detail}</div>
                            </div>
                        </li>
                    ))}
                </ul>
            </Card>

            <section className="mb-6">
                <h3 className="mb-2 text-sm font-semibold text-slate-900">Collections — where the money recorded this month is now</h3>
                {collections.rows.length === 0 ? (
                    <Card>
                        <CardBody className="text-sm text-slate-500">No payments recorded this month.</CardBody>
                    </Card>
                ) : (
                    <Table label="Collections">
                        <TableHead>
                            <Th>Method</Th>
                            <Th className="text-right">Recorded</Th>
                            {stages.map((stage) => (
                                <Th key={stage.key} className="text-right">
                                    {stage.label}
                                </Th>
                            ))}
                        </TableHead>
                        <TableBody>
                            {collections.rows.map((row) => (
                                <tr key={row.method}>
                                    <Td>
                                        <span className="font-medium text-slate-900">{row.label}</span>
                                        <div className="text-xs text-slate-500">{row.count} payment(s)</div>
                                    </Td>
                                    <Td className="text-right font-semibold">{formatCurrency(row.recorded)}</Td>
                                    {stages.map((stage) => (
                                        <Td key={stage.key} className={`text-right ${stageTone(stage.key, row[stage.key])}`}>
                                            {isZero(row[stage.key]) ? '—' : formatCurrency(row[stage.key])}
                                        </Td>
                                    ))}
                                </tr>
                            ))}
                            <tr className="bg-slate-50 font-semibold">
                                <Td>Total</Td>
                                <Td className="text-right">{formatCurrency(collections.totals.recorded)}</Td>
                                {stages.map((stage) => (
                                    <Td key={stage.key} className="text-right">
                                        {formatCurrency(collections.totals[stage.key])}
                                    </Td>
                                ))}
                            </tr>
                        </TableBody>
                    </Table>
                )}
            </section>

            <div className="mb-6 grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Handovers received</h3>
                        <p className="mt-1 text-xs text-slate-500">
                            {handovers.count} handover(s), {handovers.at_desk_count} of them desk payments.
                        </p>
                    </CardHeader>
                    <dl className="divide-y divide-slate-100 text-sm">
                        <Line label="Cash the collectors owed" value={handovers.expected} />
                        <Line label="Counted into the tills" value={handovers.received} />
                        <Line label="Spent on bons from their collections" value={handovers.bons} />
                        <Line label="Short" value={handovers.short} danger={!isZero(handovers.short)} />
                        <Line label="Of which still owed" value={handovers.short_open} danger={!isZero(handovers.short_open)} />
                        <Line label="Over (kept as the collector's credit)" value={handovers.over} />
                        <Line label="Booked into the tills (cash book)" value={handovers.into_tills} danger={handovers.into_tills !== handovers.received} />
                    </dl>
                </Card>

                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Money out</h3>
                    </CardHeader>
                    <dl className="divide-y divide-slate-100 text-sm">
                        {money_out.bons.map((row) => (
                            <Line key={row.purpose} label={`${row.label} (${row.count} bon${row.count === 1 ? '' : 's'})`} value={row.total} />
                        ))}
                        <Line label="All bons paid" value={money_out.bons_total} strong />
                        <Line label="Technician fees passed on" value={money_out.technician_fees} />
                        <Line label="Advances paid, not yet settled" value={money_out.advances_unsettled} danger={money_out.advances_overdue > 0} />
                    </dl>
                    {money_out.refunds_unpaid.length > 0 && (
                        <div className="border-t border-slate-100 px-5 py-3 text-sm">
                            <div className="mb-1 font-medium text-red-700">Reversed payments whose money has not gone back</div>
                            <ul className="space-y-1">
                                {money_out.refunds_unpaid.map((row) => (
                                    <li key={row.payment_uuid} className="flex justify-between gap-2">
                                        <span>
                                            {row.customer_name ?? 'Customer'} —{' '}
                                            {row.bon_uuid ? (
                                                <Link href={`/vouchers/${row.bon_uuid}`} className="text-blue-700 hover:underline">
                                                    {row.bon_number}
                                                </Link>
                                            ) : (
                                                row.bon_number
                                            )}{' '}
                                            <span className="text-xs text-slate-500">({row.bon_status})</span>
                                        </span>
                                        <span className="font-medium">{formatCurrency(row.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </Card>
            </div>

            <section className="mb-6">
                <h3 className="mb-2 text-sm font-semibold text-slate-900">Accounts — the month of each place money sits</h3>
                <Table label="Accounts">
                    <TableHead>
                        <Th>Account</Th>
                        <Th className="text-right">Start of month</Th>
                        <Th className="text-right">In</Th>
                        <Th className="text-right">Out</Th>
                        <Th className="text-right">End of month</Th>
                        <Th>Counted</Th>
                    </TableHead>
                    <TableBody>
                        {accounts.map((account) => (
                            <tr key={account.uuid}>
                                <Td>
                                    <Link href={`/cash/accounts/${account.uuid}`} className="font-medium text-blue-700 hover:underline">
                                        {account.name}
                                    </Link>
                                    <div className="text-xs text-slate-500">
                                        {KIND_LABELS[account.kind]}
                                        {account.holder_name && ` — ${account.holder_name}`}
                                        {!account.is_active && ' — closed'}
                                    </div>
                                </Td>
                                <Td className="text-right">{formatCurrency(account.opening)}</Td>
                                <Td className="text-right text-green-700">{formatCurrency(account.in)}</Td>
                                <Td className="text-right text-red-700">{formatCurrency(account.out)}</Td>
                                <Td className="text-right font-semibold">{formatCurrency(account.closing)}</Td>
                                <Td>
                                    <CountCell account={account} />
                                </Td>
                            </tr>
                        ))}
                    </TableBody>
                </Table>
            </section>

            <div className="mb-6 grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Cash book by kind</h3>
                        <p className="mt-1 text-xs text-slate-500">Moves between the company&apos;s own accounts change where money is, not how much there is.</p>
                    </CardHeader>
                    {movements_by_kind.length === 0 ? (
                        <CardBody className="text-sm text-slate-500">Nothing moved this month.</CardBody>
                    ) : (
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                    <th className="px-5 py-2">Kind</th>
                                    <th className="px-5 py-2 text-right">In</th>
                                    <th className="px-5 py-2 text-right">Out</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {movements_by_kind.map((row) => (
                                    <tr key={row.kind} className={row.internal ? 'text-slate-500' : ''}>
                                        <td className="px-5 py-2">
                                            {row.label}
                                            {row.internal && <span className="ml-1 text-xs">(internal)</span>}
                                        </td>
                                        <td className="px-5 py-2 text-right">{isZero(row.in) ? '—' : formatCurrency(row.in)}</td>
                                        <td className="px-5 py-2 text-right">{isZero(row.out) ? '—' : formatCurrency(row.out)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>

                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Missing money found this month</h3>
                    </CardHeader>
                    {shortages.rows.length === 0 ? (
                        <CardBody className="text-sm text-slate-500">Nothing was found missing.</CardBody>
                    ) : (
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                    <th className="px-5 py-2">Where</th>
                                    <th className="px-5 py-2 text-right">Still owed</th>
                                    <th className="px-5 py-2 text-right">Repaid</th>
                                    <th className="px-5 py-2 text-right">Written off</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {shortages.rows.map((row) => (
                                    <tr key={row.kind}>
                                        <td className="px-5 py-2">
                                            {row.label} <span className="text-xs text-slate-500">({row.count})</span>
                                        </td>
                                        <td className={`px-5 py-2 text-right ${isZero(row.open) ? '' : 'font-semibold text-red-700'}`}>{formatCurrency(row.open)}</td>
                                        <td className="px-5 py-2 text-right">{formatCurrency(row.repaid)}</td>
                                        <td className="px-5 py-2 text-right">{formatCurrency(row.written_off)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    {in_transit.count > 0 && (
                        <div className="border-t border-slate-100 px-5 py-3 text-sm text-slate-600">
                            <span className="font-medium text-slate-900">On its way right now:</span>{' '}
                            {[
                                !isZero(in_transit.handovers) && `${formatCurrency(in_transit.handovers)} between people`,
                                !isZero(in_transit.deposits) && `${formatCurrency(in_transit.deposits)} to the bank`,
                                !isZero(in_transit.branches) && `${formatCurrency(in_transit.branches)} between branches`,
                            ]
                                .filter(Boolean)
                                .join(', ')}
                            .
                        </div>
                    )}
                </Card>
            </div>

            <section className="mb-6">
                <h3 className="mb-1 text-sm font-semibold text-slate-900">Zones — collections against bills</h3>
                <p className="mb-2 text-xs text-slate-500">
                    Money taken without a receipt never reaches any ledger; a zone collecting well below its usual is where to look.
                    {is_current && ' Earlier months are counted up to the same day, so the comparison is fair mid-month.'}
                </p>
                {zones.length === 0 ? (
                    <Card>
                        <CardBody className="text-sm text-slate-500">No bills or collections this month.</CardBody>
                    </Card>
                ) : (
                    <Table label="Zones">
                        <TableHead>
                            <Th>Zone</Th>
                            <Th className="text-right">Billed</Th>
                            <Th className="text-right">Collected</Th>
                            <Th className="text-right">Of the bills</Th>
                            <Th className="text-right">Customers paid</Th>
                            <Th>6 months</Th>
                        </TableHead>
                        <TableBody>
                            {zones.map((zone) => (
                                <tr key={zone.uuid}>
                                    <Td>
                                        <span className="font-medium text-slate-900">{zone.name}</span>
                                        {zone.dropped && (
                                            <span className="ml-2">
                                                <Badge tone="red">Well below usual</Badge>
                                            </span>
                                        )}
                                        <div className="text-xs text-slate-500">{zone.collector ?? 'No responsible collector'}</div>
                                    </Td>
                                    <Td className="text-right">{formatCurrency(zone.billed)}</Td>
                                    <Td className="text-right">{formatCurrency(zone.collected)}</Td>
                                    <Td className="text-right">
                                        <Rate now={zone.rate} usual={zone.usual_rate} />
                                    </Td>
                                    <Td className="text-right">
                                        <Rate now={zone.paid_share} usual={zone.usual_paid_share} />
                                        <div className="text-xs text-slate-500">
                                            {zone.customers_paid} of {zone.customers_billed}
                                        </div>
                                    </Td>
                                    <Td>
                                        <ZoneTrend trend={zone.trend} />
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </section>
        </AppLayout>
    );
}

function stageTone(stage: Stage, amount: string): string {
    if (isZero(amount)) {
        return 'text-slate-400';
    }

    if (stage === 'not_confirmed' || stage === 'written_off') {
        return 'font-semibold text-red-700';
    }

    return stage === 'waiting' || stage === 'with_collector' ? 'text-amber-700' : '';
}

function Line({ label, value, danger = false, strong = false }: { label: string; value: string; danger?: boolean; strong?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-4 px-5 py-2">
            <dt className="text-slate-600">{label}</dt>
            <dd className={danger ? 'font-semibold text-red-700' : strong ? 'font-semibold text-slate-900' : 'text-slate-900'}>{formatCurrency(value)}</dd>
        </div>
    );
}

function CountCell({ account }: { account: AccountRow }) {
    if (account.kind === 'till') {
        return (
            <div className="flex flex-wrap items-center gap-1">
                {account.days_closed > 0 && <Badge tone={isZero(account.close_variance) ? 'green' : 'red'}>{account.days_closed} day(s) closed</Badge>}
                {account.days_not_closed.length > 0 && <Badge tone="red">{account.days_not_closed.length} not closed</Badge>}
                {!isZero(account.close_variance) && <span className="text-xs text-red-700">{formatCurrency(account.close_variance)} off</span>}
                {account.days_closed === 0 && account.days_not_closed.length === 0 && <span className="text-xs text-slate-400">Not used</span>}
            </div>
        );
    }

    if (account.statement) {
        return isZero(account.statement.difference) ? (
            <Badge tone="green">Statement matches</Badge>
        ) : (
            <Badge tone="red">Statement {formatCurrency(account.statement.difference)} off</Badge>
        );
    }

    return account.needs_statement ? <Badge tone="yellow">No statement</Badge> : <span className="text-xs text-slate-400">After month end</span>;
}
