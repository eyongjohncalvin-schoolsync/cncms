import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';
import {
    IconAlertOctagon,
    IconAlertTriangle,
    IconArrowDownLeft,
    IconArrowUpRight,
    IconBuildingBank,
    IconCalculator,
    IconCheck,
    IconFileDescription,
    IconLock,
    IconPencil,
    IconPlus,
    IconShieldCheck,
    IconTransfer,
    IconX,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { ResourcesTabs } from '@/components/resources/ResourcesTabs';
import { formatDate, formatDateTime } from '@/components/resources/ExpenseUi';
import {
    CloseStatusBadge,
    ConfirmHandoverForm,
    CountForm,
    DecisionModal,
    DepositForm,
    HandoverForm,
    HandoverStatusBadge,
    SubmitRow,
    Variance,
    accountTypeLabel,
} from '@/components/cash/CashUi';
import type { DepositAccount, HandoverTarget } from '@/components/cash/CashUi';
import { formatCurrency } from '@/lib/formatCurrency';
import type { CashAccount, CashClose, CashHandover, CashLedgerEntry, NamedRef, PageProps } from '@/types';

interface DeskAccount extends CashAccount {
    last_close: { date: string; status: string; variance: string } | null;
    days_since_close: number | null;
    shortages_30d: number;
}

interface PendingDeposit {
    uuid: string;
    from: string | null;
    to: string | null;
    amount: string;
    reference: string;
    deposited_by: string | null;
    created_at: string | null;
    has_slip: boolean;
    is_mine: boolean;
}

interface CashDeskOverview {
    accounts: DeskAccount[];
    totals: { cash_outside_bank: string; by_type: Record<string, string> };
    flags: { level: 'warning' | 'danger'; text: string }[];
    pending_closes: (CashClose & { is_mine: boolean })[];
    pending_deposits: PendingDeposit[];
    recent_handovers: CashHandover[];
    locked_periods: string[];
}

interface CashIndexProps {
    me: {
        account: CashAccount;
        today: { collected: string; spent: string; handed_over: string; received: string };
        last_close: CashClose | null;
        closed_today: boolean;
        over_max_hold: boolean;
        incoming: CashHandover[];
        outgoing: CashHandover[];
        denominations: number[];
    };
    can: { view_desk: boolean; manage: boolean; approve_large: boolean; lock_periods: boolean };
    targets: HandoverTarget[];
    deposit_accounts: DepositAccount[];
    desk: CashDeskOverview | null;
    ledger: { account: CashAccount; entries: CashLedgerEntry[] } | null;
    users: NamedRef[];
    denominations: number[];
}

type Tab = 'mine' | 'overview' | 'waiting' | 'handovers' | 'settings';

export default function CashIndex({ me, can, targets, deposit_accounts, desk, ledger, users, denominations }: CashIndexProps) {
    const { errors } = usePage<PageProps & { errors: Record<string, string> }>().props;
    const [tab, setTab] = useState<Tab>('mine');
    const [isCounting, setIsCounting] = useState(false);
    const [modal, setModal] = useState<'handover' | 'deposit' | null>(null);

    const waitingCount = desk ? desk.pending_closes.length + desk.pending_deposits.length : 0;
    const tabs: { key: Tab; label: string; badge?: number }[] = desk
        ? [
              { key: 'mine', label: 'My cash' },
              { key: 'overview', label: 'Overview' },
              { key: 'waiting', label: 'Waiting', badge: waitingCount },
              { key: 'handovers', label: 'Handovers' },
              ...(can.manage || can.lock_periods ? [{ key: 'settings' as const, label: 'Settings' }] : []),
          ]
        : [];

    const pageError = errors?.holder_uuid ?? errors?.is_active ?? errors?.period ?? errors?.handover ?? errors?.close ?? errors?.deposit;

    function viewAccount(uuid: string | null) {
        setTab('mine');
        router.get('/cash', uuid ? { account: uuid } : {}, { preserveState: true, preserveScroll: false, only: ['ledger'] });
    }

    return (
        <AppLayout title="Cash desk" breadcrumbs={[{ label: 'Resources', href: '/resources' }, { label: 'Cash desk' }]}>
            <Head title="Cash desk" />

            <ResourcesTabs active="cash" />

            {pageError && <div className="mb-4 rounded-md border-l-4 border-red-500 bg-red-100 px-4 py-3 text-sm text-red-800">{pageError}</div>}

            {tabs.length > 0 && !isCounting && (
                <div className="-mx-4 mb-4 flex gap-1 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:px-0" role="tablist" aria-label="Cash desk sections">
                    {tabs.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            role="tab"
                            aria-selected={tab === item.key}
                            onClick={() => setTab(item.key)}
                            className={`inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium transition-colors ${
                                tab === item.key ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20' : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50'
                            }`}
                        >
                            {item.label}
                            {item.badge !== undefined && item.badge > 0 && (
                                <span className={`rounded-full px-1.5 text-xs font-semibold ${tab === item.key ? 'bg-white/20 text-white' : 'bg-amber-100 text-amber-800'}`}>
                                    {item.badge}
                                </span>
                            )}
                        </button>
                    ))}
                </div>
            )}

            {isCounting ? (
                <div className="mx-auto max-w-2xl animate-fade-up">
                    <h1 className="font-display text-2xl text-slate-900">Count your cash</h1>
                    <p className="mt-1 mb-4 text-sm text-slate-500">
                        Count every note and coin in your hands and enter how many of each. You'll see what was expected only after you submit.
                    </p>
                    <CountForm denominations={me.denominations.length > 0 ? me.denominations : denominations} onDone={() => setIsCounting(false)} />
                </div>
            ) : (
                <>
                    {tab === 'mine' && (
                        <MyCash
                            me={me}
                            ledger={ledger}
                            deskAccounts={desk?.accounts ?? null}
                            onHandover={() => setModal('handover')}
                            onDeposit={() => setModal('deposit')}
                            onCount={() => setIsCounting(true)}
                            onViewAccount={viewAccount}
                        />
                    )}
                    {tab === 'overview' && desk && <Overview desk={desk} onViewAccount={viewAccount} />}
                    {tab === 'waiting' && desk && <Waiting desk={desk} can={can} />}
                    {tab === 'handovers' && desk && <HandoverHistory handovers={desk.recent_handovers} />}
                    {tab === 'settings' && desk && <DeskSettings desk={desk} can={can} users={users} />}
                </>
            )}

            <Modal open={modal === 'handover'} onClose={() => setModal(null)} title="Hand over cash">
                <HandoverForm targets={targets} onDone={() => setModal(null)} />
            </Modal>
            <Modal open={modal === 'deposit'} onClose={() => setModal(null)} title="Deposit to bank / MoMo">
                <DepositForm accounts={deposit_accounts} onDone={() => setModal(null)} />
            </Modal>
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/* My cash                                                             */
/* ------------------------------------------------------------------ */

function MyCash({
    me,
    ledger,
    deskAccounts,
    onHandover,
    onDeposit,
    onCount,
    onViewAccount,
}: {
    me: CashIndexProps['me'];
    ledger: CashIndexProps['ledger'];
    deskAccounts: DeskAccount[] | null;
    onHandover: () => void;
    onDeposit: () => void;
    onCount: () => void;
    onViewAccount: (uuid: string | null) => void;
}) {
    const account = me.account;
    const [cancelling, setCancelling] = useState<string | null>(null);
    const isOwnLedger = ledger?.account.uuid === account.uuid;

    function cancelHandover(handover: CashHandover) {
        if (!window.confirm(`Cancel the handover of ${formatCurrency(handover.amount)} to ${handover.to}?`)) {
            return;
        }

        router.post(`/cash/handovers/${handover.uuid}/cancel`, {}, {
            preserveScroll: true,
            onStart: () => setCancelling(handover.uuid),
            onFinish: () => setCancelling(null),
        });
    }

    return (
        <div className="flex flex-col gap-4">
            {/* Hero */}
            <Card className="animate-fade-up p-5 sm:p-6">
                <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-slate-500">
                            My cash · {account.name}
                        </p>
                        <p className="font-display mt-1 text-4xl font-semibold tracking-tight break-words text-slate-900 tabular-nums sm:text-5xl">
                            {formatCurrency(account.balance)}
                        </p>
                        {account.max_hold && (
                            <p className={`mt-1 text-xs ${me.over_max_hold ? 'font-semibold text-red-700' : 'text-slate-500'}`}>
                                {me.over_max_hold ? 'Above your limit of ' : 'Limit '}
                                {formatCurrency(account.max_hold)}
                                {me.over_max_hold ? ' — hand over or deposit the extra.' : ''}
                            </p>
                        )}
                    </div>
                    <div className="grid grid-cols-3 gap-2 lg:w-auto">
                        <ActionButton icon={<IconTransfer size={22} stroke={1.75} />} label="Hand over" onClick={onHandover} />
                        <ActionButton
                            icon={<IconCalculator size={22} stroke={1.75} />}
                            label={me.closed_today ? 'Count again' : 'Count today'}
                            onClick={onCount}
                            highlight={!me.closed_today}
                        />
                        <ActionButton icon={<IconBuildingBank size={22} stroke={1.75} />} label="Deposit" onClick={onDeposit} />
                    </div>
                </div>

                <dl className="mt-5 grid grid-cols-2 gap-2 border-t border-slate-100 pt-4 sm:grid-cols-4">
                    <TodayStat label="Collected today" value={me.today.collected} tone="in" />
                    <TodayStat label="Received" value={me.today.received} tone="in" />
                    <TodayStat label="Spent" value={me.today.spent} tone="out" />
                    <TodayStat label="Handed over / deposited" value={me.today.handed_over} tone="out" />
                </dl>
            </Card>

            {/* Cash waiting for ME to confirm — most urgent thing on the page. */}
            {me.incoming.length > 0 && (
                <Card className="animate-fade-up border-2 border-green-300" style={{ animationDelay: '40ms' }}>
                    <CardHeader className="bg-green-50">
                        <h2 className="text-sm font-semibold text-green-900">Cash coming to you — count it, then confirm</h2>
                    </CardHeader>
                    <ul className="divide-y divide-slate-100">
                        {me.incoming.map((handover) => (
                            <li key={handover.uuid} className="px-4 py-4">
                                <div className="mb-3 flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium text-slate-900">From {handover.from ?? '—'}</p>
                                        <p className="text-xs text-slate-500">
                                            {formatDateTime(handover.initiated_at)}
                                            {handover.note ? ` · ${handover.note}` : ''}
                                        </p>
                                    </div>
                                    <p className="font-display text-2xl font-semibold text-slate-900 tabular-nums">{formatCurrency(handover.amount)}</p>
                                </div>
                                <ConfirmHandoverForm handover={handover} />
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {/* My handovers still waiting — the code is shown big for the receiver. */}
            {me.outgoing.length > 0 && (
                <Card className="animate-fade-up" style={{ animationDelay: '60ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Your handovers waiting for the receiver</h2>
                        <p className="mt-0.5 text-xs text-slate-500">Read the code to the receiver only after they've counted the cash.</p>
                    </CardHeader>
                    <ul className="divide-y divide-slate-100">
                        {me.outgoing.map((handover) => (
                            <li key={handover.uuid} className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <p className="font-medium text-slate-900">
                                        {formatCurrency(handover.amount)} to {handover.to ?? '—'}
                                    </p>
                                    <p className="text-xs text-slate-500">Started {formatDateTime(handover.initiated_at)}</p>
                                </div>
                                <div className="flex items-center gap-3">
                                    {handover.code && (
                                        <p
                                            className="font-mono rounded-xl bg-slate-900 px-4 py-2 text-3xl font-bold tracking-[0.3em] text-white tabular-nums sm:text-4xl"
                                            aria-label={`Code ${handover.code.split('').join(' ')}`}
                                        >
                                            {handover.code}
                                        </p>
                                    )}
                                    <Button
                                        variant="ghost"
                                        onClick={() => cancelHandover(handover)}
                                        disabled={cancelling !== null}
                                        className="min-h-[44px] text-red-700"
                                    >
                                        {cancelling === handover.uuid ? <LoadingSpinner /> : <IconX size={18} stroke={2} />}
                                        Cancel
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {me.last_close && <LastCloseCard close={me.last_close} />}

            {/* Ledger */}
            {ledger && (
                <Card className="animate-fade-up" style={{ animationDelay: '100ms' }}>
                    <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold text-slate-900">
                                {isOwnLedger ? 'My movements' : `${ledger.account.name} — movements`}
                            </h2>
                            <p className="mt-0.5 text-xs text-slate-500">
                                {isOwnLedger ? 'Latest 100, newest first.' : `Balance ${formatCurrency(ledger.account.balance)} · latest 100, newest first.`}
                            </p>
                        </div>
                        {deskAccounts && (
                            <div className="w-full sm:w-64">
                                <SelectInput
                                    id="ledger_account"
                                    aria-label="Show the movements of"
                                    value={ledger.account.uuid}
                                    onChange={(e) => onViewAccount(e.target.value === account.uuid ? null : e.target.value)}
                                >
                                    <option value={account.uuid}>My till</option>
                                    {deskAccounts
                                        .filter((row) => row.uuid !== account.uuid)
                                        .map((row) => (
                                            <option key={row.uuid} value={row.uuid}>
                                                {row.name} ({accountTypeLabel(row.type)})
                                            </option>
                                        ))}
                                </SelectInput>
                            </div>
                        )}
                    </CardHeader>
                    <LedgerList entries={ledger.entries} />
                </Card>
            )}
        </div>
    );
}

function ActionButton({ icon, label, onClick, highlight = false }: { icon: ReactNode; label: string; onClick: () => void; highlight?: boolean }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex min-h-[72px] flex-col items-center justify-center gap-1 rounded-xl px-3 py-2.5 text-sm font-semibold transition-colors sm:min-w-[110px] ${
                highlight ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20 hover:bg-blue-700' : 'bg-slate-100 text-slate-800 hover:bg-slate-200'
            }`}
        >
            {icon}
            <span className="text-center leading-tight">{label}</span>
        </button>
    );
}

function TodayStat({ label, value, tone }: { label: string; value: string; tone: 'in' | 'out' }) {
    return (
        <div className="rounded-lg bg-slate-50 px-3 py-2">
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className={`mt-0.5 text-sm font-semibold tabular-nums ${Number(value) === 0 ? 'text-slate-400' : tone === 'in' ? 'text-green-700' : 'text-slate-900'}`}>
                {formatCurrency(value)}
            </dd>
        </div>
    );
}

function LastCloseCard({ close }: { close: CashClose }) {
    return (
        <Card className="animate-fade-up" style={{ animationDelay: '80ms' }}>
            <CardBody>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm font-semibold text-slate-900">Last count · {formatDate(close.business_date)}</p>
                    <CloseStatusBadge status={close.status} />
                </div>
                <dl className="mt-3 grid grid-cols-3 gap-2 text-sm">
                    <div>
                        <dt className="text-xs text-slate-500">You counted</dt>
                        <dd className="font-semibold text-slate-900 tabular-nums">{formatCurrency(close.counted)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-slate-500">Expected</dt>
                        <dd className="font-semibold text-slate-900 tabular-nums">{close.expected !== null ? formatCurrency(close.expected) : '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-slate-500">Difference</dt>
                        <dd>
                            <Variance value={close.variance} />
                        </dd>
                    </div>
                </dl>
                {close.status === 'pending' && (
                    <p className="mt-3 text-xs text-amber-700">
                        The difference waits for {close.needs_owner ? 'the owner' : 'a manager'}. Your balance changes once it's decided.
                    </p>
                )}
                {close.status === 'rejected' && (
                    <p className="mt-3 text-xs text-red-700">
                        Sent back{close.decided_by ? ` by ${close.decided_by}` : ''}: {close.decision_note ?? 'count again'}.
                    </p>
                )}
            </CardBody>
        </Card>
    );
}

function LedgerList({ entries }: { entries: CashLedgerEntry[] }) {
    if (entries.length === 0) {
        return (
            <CardBody>
                <EmptyState title="No movements yet" description="Cash payments you record and cash expenses appear here automatically." />
            </CardBody>
        );
    }

    return (
        <ul className="divide-y divide-slate-100">
            {entries.map((entry) => {
                const isIn = entry.direction === 'in';

                return (
                    <li key={entry.uuid} className={`flex items-center gap-3 px-4 py-2.5 ${entry.reversed ? 'opacity-60' : ''}`}>
                        <span
                            className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${isIn ? 'bg-green-50 text-green-600' : 'bg-slate-100 text-slate-500'}`}
                            aria-hidden="true"
                        >
                            {isIn ? <IconArrowDownLeft size={16} stroke={2} /> : <IconArrowUpRight size={16} stroke={2} />}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-slate-900">
                                {entry.label}
                                {entry.reversed && <span className="ml-1 text-xs font-normal text-slate-500">(reversal)</span>}
                            </p>
                            <p className="truncate text-xs text-slate-500">
                                {formatDateTime(entry.occurred_at)}
                                {entry.recorded_by ? ` · ${entry.recorded_by}` : ''}
                                {entry.note ? ` · ${entry.note}` : ''}
                            </p>
                        </div>
                        <div className="shrink-0 text-right">
                            <p className={`text-sm font-semibold tabular-nums ${isIn ? 'text-green-700' : 'text-slate-900'}`}>
                                {isIn ? '+' : '−'}
                                {formatCurrency(entry.amount)}
                            </p>
                            <p className="text-xs text-slate-400 tabular-nums">{formatCurrency(entry.balance_after)}</p>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

/* ------------------------------------------------------------------ */
/* Desk: overview                                                      */
/* ------------------------------------------------------------------ */

function Overview({ desk, onViewAccount }: { desk: CashDeskOverview; onViewAccount: (uuid: string) => void }) {
    return (
        <div className="flex flex-col gap-4">
            <Card className="animate-fade-up p-5 sm:p-6">
                <p className="text-sm font-medium text-slate-500">Cash outside the bank</p>
                <p className="font-display mt-1 text-4xl font-semibold tracking-tight text-slate-900 tabular-nums sm:text-5xl">
                    {formatCurrency(desk.totals.cash_outside_bank)}
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                    {Object.entries(desk.totals.by_type).map(([type, total]) => (
                        <span key={type} className="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700">
                            {accountTypeLabel(type)} <span className="font-semibold tabular-nums">{formatCurrency(total)}</span>
                        </span>
                    ))}
                </div>
            </Card>

            {desk.flags.length > 0 && (
                <Card className="animate-fade-up" style={{ animationDelay: '40ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Needs attention</h2>
                    </CardHeader>
                    <ul className="divide-y divide-slate-100">
                        {desk.flags.map((flag, index) => (
                            <li key={index} className={`flex items-start gap-2 px-4 py-2.5 text-sm ${flag.level === 'danger' ? 'text-red-800' : 'text-amber-800'}`}>
                                {flag.level === 'danger' ? (
                                    <IconAlertOctagon size={18} stroke={2} className="mt-px shrink-0 text-red-500" />
                                ) : (
                                    <IconAlertTriangle size={18} stroke={2} className="mt-px shrink-0 text-amber-500" />
                                )}
                                {flag.text}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Card className="animate-fade-up" style={{ animationDelay: '80ms' }}>
                <CardHeader>
                    <h2 className="text-sm font-semibold text-slate-900">Accounts</h2>
                    <p className="mt-0.5 text-xs text-slate-500">Tap one to see its movements.</p>
                </CardHeader>
                <ul className="divide-y divide-slate-100">
                    {desk.accounts.map((account) => {
                        const overLimit = account.max_hold !== null && Number(account.balance) > Number(account.max_hold);

                        return (
                            <li key={account.uuid}>
                                <button
                                    type="button"
                                    onClick={() => onViewAccount(account.uuid)}
                                    className="flex w-full flex-col gap-1.5 px-4 py-3 text-left transition-colors hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium text-slate-900">
                                            {account.name}
                                            <span className="ml-1.5 text-xs font-normal text-slate-500">
                                                {accountTypeLabel(account.type)}
                                                {account.holder ? ` · ${account.holder.name}` : ''}
                                            </span>
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            {account.last_close ? (
                                                <>
                                                    Last count {formatDate(account.last_close.date)}
                                                    {account.days_since_close !== null && account.days_since_close > 1 ? ` (${account.days_since_close} days ago)` : ''} ·{' '}
                                                    <Variance value={account.last_close.variance} />
                                                </>
                                            ) : ['till', 'safe', 'petty_cash'].includes(account.type) ? (
                                                'Never counted'
                                            ) : (
                                                ''
                                            )}
                                            {account.shortages_30d > 0 && (
                                                <span className="ml-1 font-semibold text-red-700">
                                                    · {account.shortages_30d} shortage{account.shortages_30d === 1 ? '' : 's'} in 30 days
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                    <div className="shrink-0 sm:text-right">
                                        <p className={`font-semibold tabular-nums ${overLimit ? 'text-red-700' : 'text-slate-900'}`}>{formatCurrency(account.balance)}</p>
                                        {account.max_hold && <p className="text-xs text-slate-400">limit {formatCurrency(account.max_hold)}</p>}
                                    </div>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </Card>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Desk: waiting for a decision                                        */
/* ------------------------------------------------------------------ */

function Waiting({ desk, can }: { desk: CashDeskOverview; can: CashIndexProps['can'] }) {
    const [decision, setDecision] = useState<{ kind: 'close' | 'deposit'; uuid: string; approve: boolean; title: string } | null>(null);

    if (desk.pending_closes.length === 0 && desk.pending_deposits.length === 0) {
        return <EmptyState title="Nothing is waiting" description="Count differences and deposits to confirm will appear here." />;
    }

    return (
        <div className="flex flex-col gap-4">
            {desk.pending_closes.length > 0 && (
                <Card className="animate-fade-up">
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Count differences</h2>
                        <p className="mt-0.5 text-xs text-slate-500">Approving posts the difference to the ledger. Sending back means the cash is counted again.</p>
                    </CardHeader>
                    <ul className="divide-y divide-slate-100">
                        {desk.pending_closes.map((close) => {
                            const canDecide = !close.is_mine && can.manage && (!close.needs_owner || can.approve_large);

                            return (
                                <li key={close.uuid} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <p className="font-medium text-slate-900">
                                            {close.account} · {formatDate(close.business_date)}
                                            {close.needs_owner && (
                                                <span className="ml-2 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-800 ring-1 ring-inset ring-red-200">Owner decides</span>
                                            )}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            Counted {formatCurrency(close.counted)} · expected {close.expected !== null ? formatCurrency(close.expected) : '—'} ·{' '}
                                            <Variance value={close.variance} />
                                            {close.closed_by ? ` · by ${close.closed_by}` : ''}
                                        </p>
                                        {close.note && <p className="mt-1 text-xs text-slate-600">“{close.note}”</p>}
                                        {close.is_mine && <p className="mt-1 text-xs text-amber-700">Your own count — someone else decides.</p>}
                                        {!close.is_mine && close.needs_owner && !can.approve_large && <p className="mt-1 text-xs text-slate-500">Large or repeated shortage — only the owner can decide.</p>}
                                    </div>
                                    {canDecide && (
                                        <div className="grid grid-cols-2 gap-2 sm:flex">
                                            <Button
                                                onClick={() => setDecision({ kind: 'close', uuid: close.uuid, approve: true, title: `Approve the difference for ${close.account}` })}
                                                className="min-h-[44px]"
                                            >
                                                <IconCheck size={18} stroke={2} />
                                                Approve
                                            </Button>
                                            <Button
                                                variant="secondary"
                                                onClick={() => setDecision({ kind: 'close', uuid: close.uuid, approve: false, title: `Send back ${close.account}'s count` })}
                                                className="min-h-[44px] text-red-700"
                                            >
                                                <IconX size={18} stroke={2} />
                                                Send back
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </Card>
            )}

            {desk.pending_deposits.length > 0 && (
                <Card className="animate-fade-up" style={{ animationDelay: '40ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Deposits to confirm</h2>
                        <p className="mt-0.5 text-xs text-slate-500">Check the slip against the bank or MoMo statement.</p>
                    </CardHeader>
                    <ul className="divide-y divide-slate-100">
                        {desk.pending_deposits.map((deposit) => (
                            <li key={deposit.uuid} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <p className="font-medium text-slate-900">
                                        {formatCurrency(deposit.amount)} · {deposit.from} → {deposit.to}
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        Ref {deposit.reference}
                                        {deposit.deposited_by ? ` · by ${deposit.deposited_by}` : ''} · {formatDateTime(deposit.created_at)}
                                    </p>
                                    {deposit.has_slip && (
                                        <a
                                            href={`/cash/deposits/${deposit.uuid}/slip`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-blue-700 hover:text-blue-800"
                                        >
                                            <IconFileDescription size={14} stroke={2} />
                                            View slip
                                        </a>
                                    )}
                                    {deposit.is_mine && <p className="mt-1 text-xs text-amber-700">Your own deposit — someone else confirms it.</p>}
                                </div>
                                {can.manage && !deposit.is_mine && (
                                    <div className="grid grid-cols-2 gap-2 sm:flex">
                                        <Button
                                            onClick={() => setDecision({ kind: 'deposit', uuid: deposit.uuid, approve: true, title: `Confirm ${formatCurrency(deposit.amount)} deposit` })}
                                            className="min-h-[44px]"
                                        >
                                            <IconCheck size={18} stroke={2} />
                                            Confirm
                                        </Button>
                                        <Button
                                            variant="secondary"
                                            onClick={() => setDecision({ kind: 'deposit', uuid: deposit.uuid, approve: false, title: `Reject ${formatCurrency(deposit.amount)} deposit` })}
                                            className="min-h-[44px] text-red-700"
                                        >
                                            <IconX size={18} stroke={2} />
                                            Reject
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <DecisionModal
                open={decision !== null}
                onClose={() => setDecision(null)}
                title={decision?.title ?? ''}
                url={decision ? `/cash/${decision.kind === 'close' ? 'closes' : 'deposits'}/${decision.uuid}/decide` : ''}
                field={decision?.kind === 'deposit' ? 'confirm' : 'approve'}
                approve={decision?.approve ?? true}
                description={
                    decision?.kind === 'deposit' && decision.approve === false
                        ? 'Rejecting reverses the deposit: the cash goes back into the depositor\'s till.'
                        : decision?.kind === 'close' && decision.approve === false
                          ? 'The holder will have to count again.'
                          : undefined
                }
            />
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Desk: handovers                                                     */
/* ------------------------------------------------------------------ */

function HandoverHistory({ handovers }: { handovers: CashHandover[] }) {
    if (handovers.length === 0) {
        return <EmptyState title="No handovers yet" />;
    }

    return (
        <Card className="animate-fade-up">
            <CardHeader>
                <h2 className="text-sm font-semibold text-slate-900">Recent handovers</h2>
            </CardHeader>
            <ul className="divide-y divide-slate-100">
                {handovers.map((handover) => (
                    <li key={handover.uuid} className="flex items-start justify-between gap-3 px-4 py-3">
                        <div className="min-w-0">
                            <p className="truncate font-medium text-slate-900">
                                {handover.from} → {handover.to}
                            </p>
                            <p className="text-xs text-slate-500">
                                {formatDateTime(handover.initiated_at)}
                                {handover.initiated_by ? ` · by ${handover.initiated_by}` : ''}
                                {handover.note ? ` · ${handover.note}` : ''}
                            </p>
                        </div>
                        <div className="shrink-0 text-right">
                            <p className="font-semibold text-slate-900 tabular-nums">{formatCurrency(handover.amount)}</p>
                            {handover.status === 'short' && handover.received_amount !== null && (
                                <p className="text-xs font-medium text-red-700">got {formatCurrency(handover.received_amount)}</p>
                            )}
                            <HandoverStatusBadge status={handover.status} />
                        </div>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/* Desk: settings                                                      */
/* ------------------------------------------------------------------ */

function previousMonth(): string {
    const now = new Date();
    const date = new Date(now.getFullYear(), now.getMonth() - 1, 1);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function DeskSettings({ desk, can, users }: { desk: CashDeskOverview; can: CashIndexProps['can']; users: NamedRef[] }) {
    const [editing, setEditing] = useState<DeskAccount | null>(null);
    const [adding, setAdding] = useState(false);
    const [isVerifying, setIsVerifying] = useState(false);

    function verify() {
        router.post('/cash/verify', {}, {
            preserveScroll: true,
            onStart: () => setIsVerifying(true),
            onFinish: () => setIsVerifying(false),
        });
    }

    return (
        <div className="flex flex-col gap-4">
            {can.manage && (
                <Card className="animate-fade-up">
                    <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold text-slate-900">Accounts</h2>
                            <p className="mt-0.5 text-xs text-slate-500">Tills are created automatically for each person. Add the safe, petty cash and bank / MoMo accounts here.</p>
                        </div>
                        <Button variant="secondary" onClick={() => setAdding(true)} className="min-h-[40px] w-full sm:w-auto">
                            <IconPlus size={17} stroke={2} />
                            Add account
                        </Button>
                    </CardHeader>
                    <ul className="divide-y divide-slate-100">
                        {desk.accounts.map((account) => (
                            <li key={account.uuid} className="flex items-center justify-between gap-3 px-4 py-3">
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-slate-900">{account.name}</p>
                                    <p className="text-xs text-slate-500">
                                        {accountTypeLabel(account.type)}
                                        {account.holder ? ` · ${account.holder.name}` : ''}
                                        {account.max_hold ? ` · limit ${formatCurrency(account.max_hold)}` : ''} · {formatCurrency(account.balance)}
                                    </p>
                                </div>
                                <Button variant="secondary" onClick={() => setEditing(account)} className="min-h-[40px] shrink-0">
                                    <IconPencil size={17} stroke={1.75} />
                                    Edit
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {can.lock_periods && (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <LockPeriodCard locked={desk.locked_periods} />
                    <Card className="animate-fade-up" style={{ animationDelay: '60ms' }}>
                        <CardHeader>
                            <h2 className="flex items-center gap-1.5 text-sm font-semibold text-slate-900">
                                <IconShieldCheck size={18} stroke={1.75} className="text-slate-400" />
                                Ledger seal
                            </h2>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Every movement is sealed to the one before it. The check re-computes the seal and every balance; any edit made behind the app's back shows up.
                            </p>
                        </CardHeader>
                        <CardBody>
                            <Button onClick={verify} disabled={isVerifying} className="min-h-[44px] w-full sm:w-auto">
                                {isVerifying ? <LoadingSpinner className="text-white" /> : <IconShieldCheck size={18} stroke={1.75} />}
                                Check the ledger seal
                            </Button>
                        </CardBody>
                    </Card>
                </div>
            )}

            <Modal open={adding} onClose={() => setAdding(false)} title="Add account">
                {adding && <AccountForm users={users} onDone={() => setAdding(false)} />}
            </Modal>
            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing ? `Edit ${editing.name}` : 'Edit account'}>
                {editing && <AccountForm key={editing.uuid} account={editing} users={users} onDone={() => setEditing(null)} />}
            </Modal>
        </div>
    );
}

const NEW_ACCOUNT_TYPES = ['safe', 'petty_cash', 'bank', 'mtn_momo', 'orange_money'];

function AccountForm({ account, users, onDone }: { account?: DeskAccount; users: NamedRef[]; onDone: () => void }) {
    const { data, setData, post, patch, processing, errors, transform } = useForm({
        type: account?.type ?? 'safe',
        name: account?.name ?? '',
        holder_uuid: account?.holder?.uuid ?? '',
        max_hold: account?.max_hold ? String(Number(account.max_hold)) : '',
        is_active: account?.is_active ?? true,
    });
    const errorList = errors as Record<string, string | undefined>;
    const holdsCash = ['safe', 'petty_cash'].includes(data.type);
    const canChangeHolder = account === undefined || (account.type !== 'till' && Number(account.balance) === 0);

    function submit(e: FormEvent) {
        e.preventDefault();

        if (account) {
            transform((form) => {
                const payload: Record<string, unknown> = { name: form.name, max_hold: form.max_hold, is_active: form.is_active };

                // Only send the holder when it really changes — the server
                // refuses any holder change while the account holds money.
                if (form.holder_uuid !== (account.holder?.uuid ?? '')) {
                    payload.holder_uuid = form.holder_uuid;
                }

                return payload as typeof form;
            });
            patch(`/cash/accounts/${account.uuid}`, { preserveScroll: true, onSuccess: () => onDone() });
        } else {
            transform((form) => ({ ...form, holder_uuid: holdsCash ? form.holder_uuid : '' }));
            post('/cash/accounts', { preserveScroll: true, onSuccess: () => onDone() });
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            {!account && (
                <SelectInput id="account_type" label="Type" required value={data.type} onChange={(e) => setData('type', e.target.value as typeof data.type)} error={errorList.type}>
                    {NEW_ACCOUNT_TYPES.map((type) => (
                        <option key={type} value={type}>
                            {accountTypeLabel(type)}
                        </option>
                    ))}
                </SelectInput>
            )}
            <TextInput id="account_name" label="Name" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errorList.name} placeholder="e.g. Office safe" />
            {(holdsCash || account?.type === 'till') && (
                <div>
                    <SelectInput
                        id="account_holder"
                        label="Held by"
                        required={holdsCash}
                        disabled={!canChangeHolder}
                        value={data.holder_uuid}
                        onChange={(e) => setData('holder_uuid', e.target.value)}
                        error={errorList.holder_uuid}
                    >
                        <option value="">Choose a person</option>
                        {users.map((user) => (
                            <option key={user.uuid} value={user.uuid}>
                                {user.name}
                            </option>
                        ))}
                    </SelectInput>
                    {!canChangeHolder && (
                        <p className="mt-1 text-xs text-slate-500">
                            {account?.type === 'till' ? 'A till always belongs to its person.' : 'Hand the cash over first — the holder can only change when the account is empty.'}
                        </p>
                    )}
                </div>
            )}
            {(holdsCash || account?.type === 'till') && (
                <TextInput
                    id="account_max_hold"
                    label="Most cash it may hold (FCFA, optional)"
                    type="number"
                    inputMode="numeric"
                    min="0"
                    step="1"
                    value={data.max_hold}
                    onChange={(e) => setData('max_hold', e.target.value)}
                    error={errorList.max_hold}
                    hint="Above this the desk shows a red flag."
                />
            )}
            {account && (
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={data.is_active}
                        onChange={(e) => setData('is_active', e.target.checked)}
                        className="h-5 w-5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                    />
                    Active
                    {errorList.is_active && <span className="text-xs text-red-600">{errorList.is_active}</span>}
                </label>
            )}
            <SubmitRow processing={processing} label={account ? 'Save changes' : 'Add account'} onCancel={onDone} />
        </form>
    );
}

function LockPeriodCard({ locked }: { locked: string[] }) {
    const latestFinished = previousMonth();
    const { data, setData, post, processing, errors, reset } = useForm({ period: latestFinished, note: '' });

    function submit(e: FormEvent) {
        e.preventDefault();

        if (!window.confirm(`Lock ${data.period}? No movement can be added to or changed in that month afterwards.`)) {
            return;
        }

        post('/cash/periods/lock', { preserveScroll: true, onSuccess: () => reset('note') });
    }

    return (
        <Card className="animate-fade-up">
            <CardHeader>
                <h2 className="flex items-center gap-1.5 text-sm font-semibold text-slate-900">
                    <IconLock size={18} stroke={1.75} className="text-slate-400" />
                    Lock a finished month
                </h2>
                <p className="mt-0.5 text-xs text-slate-500">Once locked, nothing dated in that month can be recorded or changed.</p>
            </CardHeader>
            <CardBody>
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <TextInput id="lock_period" label="Month" type="month" required max={latestFinished} value={data.period} onChange={(e) => setData('period', e.target.value)} error={errors.period} />
                        <TextInput id="lock_note" label="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} error={errors.note} />
                    </div>
                    <Button type="submit" variant="warning" disabled={processing || locked.includes(data.period)} className="min-h-[44px] w-full sm:w-auto sm:self-start">
                        {processing ? <LoadingSpinner className="text-white" /> : <IconLock size={18} stroke={1.75} />}
                        {locked.includes(data.period) ? 'Already locked' : `Lock ${data.period}`}
                    </Button>
                </form>
                {locked.length > 0 && (
                    <div className="mt-4 border-t border-slate-100 pt-3">
                        <p className="text-xs font-medium text-slate-500">Locked</p>
                        <div className="mt-1.5 flex flex-wrap gap-1.5">
                            {locked.map((period) => (
                                <span key={period} className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                    <IconLock size={12} stroke={2} />
                                    {period}
                                </span>
                            ))}
                        </div>
                    </div>
                )}
            </CardBody>
        </Card>
    );
}
