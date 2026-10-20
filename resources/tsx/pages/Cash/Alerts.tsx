import { Head, Link } from '@inertiajs/react';
import { IconAlertTriangle, IconCircleCheck } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { formatCurrency } from '@/lib/formatCurrency';

interface Alert {
    key: string;
    severity: 'danger' | 'warning';
    title: string;
    advice: string;
    items: { label: string; detail: string; amount: string | null; link: string | null }[];
}

/**
 * Cash alerts — App\Http\Controllers\CashAlertController
 * (docs/plans/cash-controls.md, wave 6). Everything in the cash controls that
 * needs a person: cash not handed over, shortages, mobile money missing from
 * the wallet, receipts a customer holds that nobody recorded, unaccounted
 * receipt numbers, tills not closed, unjustified advances, unsigned vouchers.
 * Computed live — an item disappears as soon as its cause is fixed.
 */
export default function CashAlerts({ alerts }: { alerts: Alert[] }) {
    return (
        <AppLayout title="Cash alerts" breadcrumbs={[{ label: 'Cash book', href: '/cash' }, { label: 'Alerts' }]}>
            <Head title="Cash alerts" />

            <div className="mb-6">
                <h2 className="font-display text-2xl text-slate-900">Cash alerts</h2>
                <p className="mt-1 text-sm text-slate-500">What needs someone to act today. Each item disappears once it is dealt with.</p>
            </div>

            {alerts.length === 0 ? (
                <Card>
                    <CardBody className="flex items-center gap-3 text-sm text-green-800">
                        <IconCircleCheck size={22} stroke={1.75} className="text-green-600" />
                        Nothing needs attention — every handover, receipt number and voucher is accounted for.
                    </CardBody>
                </Card>
            ) : (
                <div className="flex flex-col gap-4">
                    {alerts.map((alert) => (
                        <Card key={alert.key}>
                            <CardHeader>
                                <div className="flex flex-wrap items-center gap-2">
                                    <IconAlertTriangle size={18} stroke={1.75} className={alert.severity === 'danger' ? 'text-red-600' : 'text-amber-500'} />
                                    <h3 className="text-sm font-semibold text-slate-900">{alert.title}</h3>
                                    <Badge tone={alert.severity === 'danger' ? 'red' : 'yellow'}>{alert.items.length}</Badge>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">{alert.advice}</p>
                            </CardHeader>
                            <ul className="divide-y divide-slate-100">
                                {alert.items.map((item, index) => (
                                    <li key={index} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                                        <div>
                                            {item.link ? (
                                                <Link href={item.link} className="font-medium text-blue-700 hover:underline">
                                                    {item.label}
                                                </Link>
                                            ) : (
                                                <span className="font-medium text-slate-900">{item.label}</span>
                                            )}
                                            <div className="text-xs text-slate-500">{item.detail}</div>
                                        </div>
                                        {item.amount !== null && <span className="font-semibold text-slate-900">{formatCurrency(item.amount)}</span>}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
