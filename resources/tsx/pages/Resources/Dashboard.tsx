import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { IconCash, IconPercentage, IconReceipt2, IconTrendingDown, IconTrendingUp } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { TextInput } from '@/components/ui/TextInput';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ErrorBoundary } from '@/components/ui/ErrorBoundary';
import { formatCurrency } from '@/lib/formatCurrency';
import type { PageProps, ResourcesDashboard } from '@/types';

const CATEGORY_COLORS = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#64748b'];
const MANAGE_CATEGORY_ROLES = ['super', 'admin'];

export default function ResourcesDashboardPage({ period, income, expenses, pnl, budgets }: ResourcesDashboard) {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role ?? null;
    const canManageCategories = role !== null && MANAGE_CATEGORY_ROLES.includes(role);

    const [isLoading, setIsLoading] = useState(false);

    const incomeExpenseChartData = useMemo(
        () => [
            { name: 'Income (verified)', value: Number(income.verified) },
            { name: 'Expenses', value: Number(expenses.total) },
        ],
        [income.verified, expenses.total],
    );

    // Memoized so the derived net-positive check isn't recomputed on every
    // render — only when the underlying P&L payload changes.
    const isNetPositive = useMemo(() => Number(pnl.net) >= 0, [pnl.net]);

    function applyPeriod(nextPeriod: string) {
        router.get(
            '/resources',
            { period: nextPeriod },
            {
                preserveState: true,
                replace: true,
                onStart: () => setIsLoading(true),
                onFinish: () => setIsLoading(false),
            },
        );
    }

    return (
        <AppLayout title="Resources — P&L Dashboard">
            <Head title="Resources" />

            <div className="mb-4 flex flex-wrap items-end justify-between gap-3 animate-fade-up">
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Profit &amp; Loss — {period}</h1>
                    <p className="text-sm text-slate-500">Income vs. expenditure summary for the selected period.</p>
                </div>
                <div className="flex w-full flex-wrap items-end gap-2 sm:w-auto">
                    <div className="w-40">
                        <TextInput
                            id="period"
                            label="Period"
                            type="month"
                            value={period}
                            onChange={(e) => applyPeriod(e.target.value)}
                        />
                    </div>
                    {isLoading && <LoadingSpinner className="mb-2 text-blue-600" label="Loading period" />}
                    {canManageCategories && (
                        <Link href="/resources/categories" className="flex-1 sm:flex-none">
                            <Button variant="secondary" className="w-full sm:w-auto">Manage Categories</Button>
                        </Link>
                    )}
                    <Link href="/resources/expenditures" className="flex-1 sm:flex-none">
                        <Button variant="secondary" className="w-full sm:w-auto">View Expenditures</Button>
                    </Link>
                    <Link href="/resources/expenditures/create" className="flex-1 sm:flex-none">
                        <Button className="w-full sm:w-auto">Record Expense</Button>
                    </Link>
                </div>
            </div>

            {/* Hero: the Net Profit/Loss figure is the one number this page exists to
                answer, so it gets its own high-contrast, large-type treatment ahead of
                the supporting stat grid — a loss reads as unmistakably red, never just
                a negative number in neutral styling. */}
            <Card
                className={`mb-4 animate-fade-up border-2 p-6 sm:p-8 ${
                    isNetPositive ? 'border-green-300 bg-green-100' : 'border-red-300 bg-red-100'
                }`}
                style={{ animationDelay: '40ms' }}
            >
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className={`text-sm font-medium ${isNetPositive ? 'text-green-700' : 'text-red-700'}`}>
                            Net {isNetPositive ? 'Profit' : 'Loss'} — {period}
                        </p>
                        <p
                            className={`font-display mt-1 text-3xl font-semibold tracking-tight break-words sm:text-5xl ${
                                isNetPositive ? 'text-green-700' : 'text-red-700'
                            }`}
                        >
                            {formatCurrency(pnl.net)}
                        </p>
                    </div>
                    <div
                        className={`flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold ${
                            isNetPositive ? 'bg-white text-green-700 ring-1 ring-inset ring-green-300' : 'bg-white text-red-700 ring-1 ring-inset ring-red-300'
                        }`}
                    >
                        {isNetPositive ? <IconTrendingUp size={18} stroke={2} /> : <IconTrendingDown size={18} stroke={2} />}
                        {isNetPositive ? 'Profit' : 'Loss'}
                    </div>
                </div>
            </Card>

            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div className="animate-fade-up" style={{ animationDelay: '100ms' }}>
                    <StatCard
                        label="Verified Income"
                        value={formatCurrency(income.verified)}
                        hint={`${income.payment_count} payments recorded`}
                        icon={<IconCash size={20} stroke={1.75} />}
                        tone="green"
                    />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '150ms' }}>
                    <StatCard
                        label="Total Expenses"
                        value={formatCurrency(expenses.total)}
                        icon={<IconReceipt2 size={20} stroke={1.75} />}
                        tone="red"
                    />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '200ms' }}>
                    <StatCard
                        label="Net"
                        value={formatCurrency(pnl.net)}
                        hint={isNetPositive ? 'Profit' : 'Loss'}
                        icon={isNetPositive ? <IconTrendingUp size={20} stroke={1.75} /> : <IconTrendingDown size={20} stroke={1.75} />}
                        tone={isNetPositive ? 'green' : 'red'}
                    />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '250ms' }}>
                    <StatCard
                        label="Margin"
                        value={`${pnl.margin_pct}%`}
                        icon={<IconPercentage size={20} stroke={1.75} />}
                        tone="purple"
                    />
                </div>
            </div>

            <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card className="animate-fade-up" style={{ animationDelay: '320ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Income vs. Expenses</h2>
                    </CardHeader>
                    <CardBody>
                        {/* Isolated so a malformed/unexpected income-expense payload can only
                            take out this chart, not the whole dashboard — recharts can throw
                            during layout when fed unexpected values. */}
                        <ErrorBoundary compact>
                            {/* The figures behind this chart are already stated in plain text
                                above (Verified Income / Total Expenses StatCards), so the SVG
                                chart is a visual confirmation, not the only way to reach the
                                numbers — role="img" + aria-label give screen readers the same
                                summary sighted users get from the bars. */}
                            <div
                                style={{ width: '100%', height: 260 }}
                                role="img"
                                aria-label={`Bar chart comparing verified income of ${formatCurrency(income.verified)} against total expenses of ${formatCurrency(expenses.total)}.`}
                            >
                                <ResponsiveContainer>
                                    <BarChart data={incomeExpenseChartData}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                                        <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                                        <YAxis tickFormatter={(value: number) => new Intl.NumberFormat('en-US', { notation: 'compact' }).format(value)} />
                                        <Tooltip formatter={(value: number) => formatCurrency(value)} />
                                        <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                                            <Cell fill="#16a34a" />
                                            <Cell fill="#dc2626" />
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </ErrorBoundary>
                    </CardBody>
                </Card>

                <Card className="animate-fade-up" style={{ animationDelay: '380ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Expenses by Category</h2>
                    </CardHeader>
                    <CardBody>
                        {expenses.by_category.length === 0 ? (
                            <EmptyState title="No expenses recorded" description="No expenditures were recorded for this period." />
                        ) : (
                            <ErrorBoundary compact>
                                {/* Same rationale as the bar chart above — the Category
                                    Breakdown table below already lists every slice as text,
                                    so this is a supplementary view, not the only route to
                                    the numbers. */}
                                <div
                                    style={{ width: '100%', height: 260 }}
                                    role="img"
                                    aria-label={`Pie chart of expenses by category: ${expenses.by_category
                                        .map((row) => `${row.name} ${formatCurrency(row.amount)}`)
                                        .join(', ')}.`}
                                >
                                    <ResponsiveContainer>
                                        <PieChart>
                                            <Pie
                                                data={expenses.by_category}
                                                dataKey="amount"
                                                nameKey="name"
                                                cx="50%"
                                                cy="50%"
                                                outerRadius={90}
                                                label={(entry: { name: string }) => entry.name}
                                            >
                                                {expenses.by_category.map((entry, index) => (
                                                    <Cell key={entry.name} fill={CATEGORY_COLORS[index % CATEGORY_COLORS.length]} />
                                                ))}
                                            </Pie>
                                            <Tooltip formatter={(value: number) => formatCurrency(value)} />
                                            <Legend wrapperStyle={{ fontSize: 12 }} />
                                        </PieChart>
                                    </ResponsiveContainer>
                                </div>
                            </ErrorBoundary>
                        )}
                    </CardBody>
                </Card>
            </div>

            <Card className="mb-4 animate-fade-up p-0" style={{ animationDelay: '440ms' }}>
                <CardHeader>
                    <h2 className="text-sm font-semibold text-slate-900">Category Breakdown</h2>
                </CardHeader>
                {expenses.by_category.length === 0 ? (
                    <CardBody>
                        <EmptyState title="No expenses recorded" description="No expenditures were recorded for this period." />
                    </CardBody>
                ) : (
                    <Table>
                        <TableHead>
                            <Th>Category</Th>
                            <Th>Amount</Th>
                            <Th>Entries</Th>
                        </TableHead>
                        <TableBody>
                            {expenses.by_category.map((row) => (
                                <tr key={row.name} className="transition-colors hover:bg-slate-50/70">
                                    <Td>{row.name}</Td>
                                    <Td className="font-medium text-slate-900">{formatCurrency(row.amount)}</Td>
                                    <Td>{row.count}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>

            {budgets.length > 0 && (
                <Card className="animate-fade-up p-0" style={{ animationDelay: '500ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Budget vs. Actual</h2>
                    </CardHeader>
                    <Table>
                        <TableHead>
                            <Th>Category</Th>
                            <Th>Budgeted</Th>
                            <Th>Actual</Th>
                            <Th>Variance</Th>
                            <Th>Variance %</Th>
                        </TableHead>
                        <TableBody>
                            {budgets.map((row) => (
                                <tr key={row.category} className="transition-colors hover:bg-slate-50/70">
                                    <Td>{row.category}</Td>
                                    <Td>{formatCurrency(row.budgeted)}</Td>
                                    <Td>{formatCurrency(row.actual)}</Td>
                                    <Td className={Number(row.variance) < 0 ? 'font-medium text-red-600' : 'font-medium text-green-600'}>
                                        {formatCurrency(row.variance)}
                                    </Td>
                                    <Td>{row.variance_pct}%</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </AppLayout>
    );
}
