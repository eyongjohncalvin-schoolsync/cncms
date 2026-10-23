import { Head, router, useForm } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/TextInput';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { formatCurrency } from '@/lib/formatCurrency';
import { monthLabel } from '@/lib/expenditures';

interface BudgetRow {
    uuid: string;
    name: string;
    is_active: boolean;
    previous_budget: string | null;
    previous_actual: string;
    budget: string | null;
    actual: string;
    pct: number | null;
    level: 'ok' | 'amber' | 'red';
}

interface Props {
    grid: {
        month: string;
        previous: string;
        closed: boolean;
        cap: string | null;
        budget_total: string;
        previous_budget_total: string;
        rows: BudgetRow[];
    };
    current_month: string;
}

const LEVEL_TONE = { ok: 'slate', amber: 'yellow', red: 'red' } as const;

/**
 * Resources → Budgets (E5 of docs/plans/expenditures-and-spending-controls.md):
 * one month's budget per category next to last month, with the total
 * against the monthly cap. Warnings only — amber at 80 %, red at 100 % —
 * a budget never blocks a bon.
 */
export default function Budgets({ grid, current_month }: Props) {
    const { data, setData, put, processing, errors } = useForm<{ month: string; amounts: Record<string, string> }>({
        month: grid.month,
        amounts: Object.fromEntries(grid.rows.map((row) => [row.uuid, row.budget ?? ''])),
    });

    const total = Object.values(data.amounts).reduce((sum, value) => sum + (Number(value) || 0), 0);
    const overCap = grid.cap !== null && total > Number(grid.cap);

    return (
        <AppLayout title="Budgets" breadcrumbs={[{ label: 'Resources', href: '/resources' }, { label: 'Budgets' }]}>
            <Head title="Budgets" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Budgets — {monthLabel(grid.month)}</h1>
                    <p className="mt-1 max-w-2xl text-sm text-slate-500">
                        A budget per category warns at 80% and turns red at 100% on the bon form, the expenditures page and the dashboard. It never blocks a bon — the monthly cap does that.
                    </p>
                </div>
                <div className="flex flex-wrap items-end gap-2">
                    <div className="w-44">
                        <TextInput id="month" label="Month" type="month" value={grid.month} onChange={(e) => e.target.value && router.get('/resources/budgets', { month: e.target.value })} />
                    </div>
                    {!grid.closed && (
                        <Button variant="secondary" onClick={() => router.post('/resources/budgets/copy', { month: grid.month }, { preserveScroll: true })}>
                            Copy from {monthLabel(grid.previous)}
                        </Button>
                    )}
                </div>
            </div>

            {grid.closed && <p className="mb-4 rounded-lg bg-slate-100 p-3 text-sm text-slate-700">{monthLabel(grid.month)} is closed — its budgets can no longer change.</p>}
            {grid.month < current_month && !grid.closed && <p className="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">This month has ended.</p>}

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    put('/resources/budgets', { preserveScroll: true });
                }}
            >
                <Card className="p-0">
                    <Table label="Budgets">
                        <TableHead>
                            <Th>Category</Th>
                            <Th className="text-right">{monthLabel(grid.previous)} budget</Th>
                            <Th className="text-right">{monthLabel(grid.previous)} spent</Th>
                            <Th className="text-right">Budget</Th>
                            <Th className="text-right">Spent</Th>
                            <Th>Status</Th>
                        </TableHead>
                        <TableBody>
                            {grid.rows.map((row) => (
                                <tr key={row.uuid}>
                                    <Td>
                                        {row.name}
                                        {!row.is_active && <span className="ml-1 text-xs text-slate-400">(inactive)</span>}
                                    </Td>
                                    <Td className="text-right text-slate-500">{row.previous_budget ? formatCurrency(row.previous_budget) : '—'}</Td>
                                    <Td className="text-right text-slate-500">{formatCurrency(row.previous_actual)}</Td>
                                    <Td className="text-right">
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            aria-label={`${row.name} budget`}
                                            disabled={grid.closed}
                                            value={data.amounts[row.uuid] ?? ''}
                                            onChange={(e) => setData('amounts', { ...data.amounts, [row.uuid]: e.target.value })}
                                            className="w-32 rounded-lg border border-slate-300 px-2 py-1 text-right text-sm disabled:bg-slate-100"
                                            placeholder="No budget"
                                        />
                                    </Td>
                                    <Td className="text-right font-medium">{formatCurrency(row.actual)}</Td>
                                    <Td>{row.pct !== null && <Badge tone={LEVEL_TONE[row.level]}>{row.pct}%</Badge>}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>

                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <p className={`text-sm ${overCap ? 'font-medium text-red-700' : 'text-slate-600'}`}>
                        Total budgeted {formatCurrency(String(total))}
                        {grid.cap !== null ? ` of a ${formatCurrency(grid.cap)} monthly cap` : ' (no monthly cap set)'}
                        {overCap ? ' — above the cap.' : '.'}
                        {errors.month && <span className="ml-2 text-red-700">{errors.month}</span>}
                    </p>
                    {!grid.closed && (
                        <Button type="submit" disabled={processing}>
                            Save budgets
                        </Button>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
