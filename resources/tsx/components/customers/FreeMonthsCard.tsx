import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { IconGift } from '@tabler/icons-react';
import { Card, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatMonthYear } from '@/lib/formatMonthYear';
import type { CustomerCoverage, CustomerFreeMonths, FreeMonthGrantRow, FreeMonthGrantStatus } from '@/types';

const STATUS: Record<FreeMonthGrantStatus, { label: string; tone: 'slate' | 'green' | 'yellow' | 'red' | 'blue' }> = {
    awaiting_installation_payment: { label: 'Starts once installation is paid', tone: 'blue' },
    pending_approval: { label: 'Awaiting approval', tone: 'yellow' },
    approved: { label: 'Given', tone: 'green' },
    rejected: { label: 'Rejected', tone: 'red' },
    cancelled: { label: 'Forfeited', tone: 'slate' },
};

/**
 * Free months on the customer page (docs/plans/installation-and-promotions.md,
 * phase 2): the coverage line ("TV free until Dec · paid until Mar · first
 * bill Apr"), every free-month request with its status, approve / reject for
 * someone other than whoever asked (D8), and "Give free months" for poor
 * service (D12) — every or some services, optionally with an arrears
 * write-off. Free months are zero-value: never income, credit or arrears.
 */
export function FreeMonthsCard({ customerUuid, freeMonths, coverage }: { customerUuid: string; freeMonths: CustomerFreeMonths; coverage: CustomerCoverage | undefined }) {
    const [open, setOpen] = useState(false);

    return (
        <Card className="p-0">
            <CardHeader className="border-b border-slate-100">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                            <IconGift size={18} stroke={1.75} />
                        </span>
                        <div>
                            <h3 className="text-base font-semibold text-slate-900">Free months</h3>
                            <p className="mt-0.5 text-xs text-slate-500">{coverage?.label ?? 'No free months or paid months ahead.'}</p>
                        </div>
                    </div>
                    {freeMonths.can_request && (
                        <Button type="button" variant="secondary" onClick={() => setOpen(true)}>
                            Give free months
                        </Button>
                    )}
                </div>
            </CardHeader>

            {freeMonths.grants.length === 0 ? (
                <div className="p-4">
                    <EmptyState title="No free months given" />
                </div>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {freeMonths.grants.map((grant) => (
                        <GrantRow key={grant.uuid} grant={grant} />
                    ))}
                </ul>
            )}

            <CompensationModal open={open} onClose={() => setOpen(false)} customerUuid={customerUuid} freeMonths={freeMonths} />
        </Card>
    );
}

function GrantRow({ grant }: { grant: FreeMonthGrantRow }) {
    const decide = (decision: 'approve' | 'reject') => {
        const reason = decision === 'reject' ? window.prompt('Why are these free months rejected? (optional)') : null;
        if (decision === 'reject' && reason === null) return;

        router.post(`/free-months/${grant.uuid}/${decision}`, decision === 'reject' ? { reason } : {}, { preserveScroll: true });
    };

    return (
        <li className="flex flex-col gap-3 px-5 py-4 text-sm sm:flex-row sm:items-start sm:justify-between">
            <div className="flex flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold text-slate-900">
                        {grant.service}: {grant.months} month{grant.months === 1 ? '' : 's'}
                    </span>
                    <Badge tone={STATUS[grant.status].tone}>{STATUS[grant.status].label}</Badge>
                    <span className="text-xs text-slate-500">{grant.reason_label}</span>
                </div>
                {grant.start_period && grant.end_period && grant.status === 'approved' && (
                    <p className="text-slate-600">
                        {formatMonthYear(grant.start_period)} – {formatMonthYear(grant.end_period)}
                    </p>
                )}
                <p className="text-xs text-slate-500">
                    {grant.requested_by_name ? `Asked by ${grant.requested_by_name}` : 'Asked'}
                    {grant.decided_by_name ? `, decided by ${grant.decided_by_name}` : ''}
                    {grant.note ? ` — ${grant.note}` : ''}
                    {grant.rejection_reason ? ` · Rejected: ${grant.rejection_reason}` : ''}
                    {grant.cancel_reason ? ` · ${grant.cancel_reason}` : ''}
                </p>
            </div>
            {grant.can.decide && (
                <div className="flex shrink-0 flex-wrap gap-2">
                    <Button type="button" variant="warning" onClick={() => decide('approve')}>
                        Approve
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => decide('reject')}>
                        Reject
                    </Button>
                </div>
            )}
        </li>
    );
}

function CompensationModal({
    open,
    onClose,
    customerUuid,
    freeMonths,
}: {
    open: boolean;
    onClose: () => void;
    customerUuid: string;
    freeMonths: CustomerFreeMonths;
}) {
    const { data, setData, post, processing, errors, reset } = useForm<{ months: string; reason: string; service_uuids: string[]; write_off_amount: string }>({
        months: '1',
        reason: '',
        service_uuids: [],
        write_off_amount: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(`/customers/${customerUuid}/free-months`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Modal open={open} onClose={onClose} title="Give free months (compensation)">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    For poor service. Someone else with approval rights must confirm. Each service&apos;s months are added after its existing free and
                    paid months, so nothing already paid is lost.
                </p>
                <TextInput
                    id="free-months"
                    label={`Months (1 – ${freeMonths.max_months})`}
                    type="number"
                    min="1"
                    max={freeMonths.max_months}
                    required
                    value={data.months}
                    onChange={(e) => setData('months', e.target.value)}
                    error={errors.months}
                />
                {freeMonths.services.length > 1 && (
                    <fieldset>
                        <legend className="mb-1 text-sm font-medium text-slate-700">Services (none ticked = all)</legend>
                        {freeMonths.services.map((service) => (
                            <label key={service.uuid} className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={data.service_uuids.includes(service.uuid)}
                                    onChange={(e) =>
                                        setData(
                                            'service_uuids',
                                            e.target.checked ? [...data.service_uuids, service.uuid] : data.service_uuids.filter((uuid) => uuid !== service.uuid),
                                        )
                                    }
                                    className="h-4 w-4 rounded border-slate-300"
                                />
                                {service.name}
                            </label>
                        ))}
                    </fieldset>
                )}
                <TextInput id="free-reason" label="Reason" required value={data.reason} onChange={(e) => setData('reason', e.target.value)} error={errors.reason} />
                <TextInput
                    id="free-write-off"
                    label="Also write off arrears (FCFA, optional)"
                    type="number"
                    min="0"
                    value={data.write_off_amount}
                    onChange={(e) => setData('write_off_amount', e.target.value)}
                    error={errors.write_off_amount}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Request free months
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
