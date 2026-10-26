import { FormEvent, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { IconAlertTriangle, IconDeviceMobile } from '@tabler/icons-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { periodLabel } from '@/components/customers/InstallationSection';
import { formatCurrency } from '@/lib/formatCurrency';
import type { FieldInstallationDetail } from '@/types';

const STATUS: Record<FieldInstallationDetail['status'], { label: string; tone: 'yellow' | 'green' | 'red' }> = {
    pending: { label: 'Pending activation', tone: 'yellow' },
    activated: { label: 'Activated', tone: 'green' },
    rejected: { label: 'Rejected', tone: 'red' },
};

interface ActivationForm {
    zone_uuid: string;
    installation_offer_uuid: string;
    connected_on: string;
    service_plans: { service_uuid: string; free_from: string | null; free_months: number }[];
    installation_reason: string;
    note: string;
}

/**
 * A customer a field agent registered offline
 * (docs/plans/installation-and-promotions.md, phase 3): what the agent
 * proposed and collected (with the receipts the phone printed), possible
 * duplicates, and — while pending — the office's Activate (confirm or
 * correct zone, offer, free months, connection date) and Reject (with a
 * reason: payments rejected, receipts voided, refund bon, archived).
 */
export function FieldInstallationCard({ customerUuid, zoneUuid, field }: { customerUuid: string; zoneUuid: string; field: FieldInstallationDetail }) {
    const [activating, setActivating] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const { proposal } = field;

    return (
        <Card className="p-0">
            <CardHeader className="border-b border-slate-100">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                            <IconDeviceMobile size={18} stroke={1.75} />
                        </span>
                        <div>
                            <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900">
                                Registered in the field
                                <Badge tone={STATUS[field.status].tone}>{STATUS[field.status].label}</Badge>
                            </h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                By {field.submitted_by_name ?? 'an agent'}
                                {field.collected_at ? ` on ${new Date(field.collected_at).toLocaleString()}` : ''}
                                {field.status === 'pending' ? ' — not billed until activated.' : ''}
                            </p>
                        </div>
                    </div>
                    {field.can_decide && (
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" onClick={() => setActivating(true)}>
                                Activate
                            </Button>
                            <Button type="button" variant="danger" onClick={() => setRejecting(true)}>
                                Reject
                            </Button>
                        </div>
                    )}
                </div>
            </CardHeader>
            <CardBody className="flex flex-col gap-4 text-sm">
                {field.possible_duplicates.length > 0 && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900">
                        <p className="flex items-center gap-1.5 font-semibold">
                            <IconAlertTriangle size={16} stroke={2} /> Possible duplicate of
                        </p>
                        <ul className="mt-1 list-inside list-disc">
                            {field.possible_duplicates.map((duplicate) => (
                                <li key={duplicate.uuid}>
                                    <Link href={`/customers/${duplicate.uuid}`} className="font-medium underline">
                                        {duplicate.name}
                                    </Link>
                                    {duplicate.phone ? ` · ${duplicate.phone}` : ''}
                                    {duplicate.zone ? ` · ${duplicate.zone}` : ''} · {duplicate.status}
                                </li>
                            ))}
                        </ul>
                        <p className="mt-1 text-xs">Reject this registration if it is the same person, or activate it if not.</p>
                    </div>
                )}

                <dl className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                    <div>
                        <dt className="text-xs text-slate-500">Offer</dt>
                        <dd className="text-slate-900">{proposal.offer_name ?? 'None'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-slate-500">Connected on</dt>
                        <dd className="text-slate-900">{proposal.connected_on ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-slate-500">Installation paid</dt>
                        <dd className="text-slate-900">
                            {formatCurrency(proposal.paid)}
                            {proposal.fee ? ` of ${formatCurrency(proposal.fee)}` : ''}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-slate-500">Months per service</dt>
                        <dd className="text-slate-900">
                            {proposal.service_plans.length === 0
                                ? '—'
                                : proposal.service_plans
                                      .map(
                                          (plan) =>
                                              `${plan.service_name}: ${plan.free_months > 0 ? `${plan.free_months} free from ${periodLabel(plan.free_from)}` : 'no free months'}${plan.paid_months > 0 ? `, ${plan.paid_months} paid upfront` : ''}`,
                                      )
                                      .join(' · ')}
                        </dd>
                    </div>
                    {proposal.reason && (
                        <div className="sm:col-span-2">
                            <dt className="text-xs text-slate-500">Agent&apos;s note</dt>
                            <dd className="text-slate-900">{proposal.reason}</dd>
                        </div>
                    )}
                </dl>

                {field.payments.length > 0 && (
                    <div>
                        <p className="text-xs text-slate-500">Money taken in the field</p>
                        <ul className="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-100">
                            {field.payments.map((payment) => (
                                <li key={payment.uuid} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                    <Link href={`/payments/${payment.uuid}`} className="font-medium text-blue-700 hover:underline">
                                        {formatCurrency(payment.amount)} · {payment.purpose === 'installation' ? 'Installation' : 'Months paid upfront'}
                                    </Link>
                                    <span className="text-xs text-slate-500">
                                        {payment.receipt_number ?? 'no receipt'} · {payment.verification_status}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {field.status !== 'pending' && (
                    <p className="text-xs text-slate-500">
                        {STATUS[field.status].label} by {field.decided_by_name ?? '—'}
                        {field.decided_at ? ` on ${new Date(field.decided_at).toLocaleString()}` : ''}
                        {field.decision_note ? ` — ${field.decision_note}` : ''}
                        {field.corrections ? ` · Corrected: ${Object.keys(field.corrections).join(', ').replace(/_/g, ' ')}` : ''}
                        {field.refund_voucher_number ? ` · Refund bon ${field.refund_voucher_number} (${field.refund_voucher_status})` : ''}
                    </p>
                )}
            </CardBody>

            {field.can_decide && (
                <>
                    <ActivateModal open={activating} onClose={() => setActivating(false)} field={field} zoneUuid={zoneUuid} />
                    <RejectModal open={rejecting} onClose={() => setRejecting(false)} field={field} customerUuid={customerUuid} />
                </>
            )}
        </Card>
    );
}

function initialForm(field: FieldInstallationDetail, zoneUuid: string): ActivationForm {
    const first = field.form.installation_month;

    return {
        zone_uuid: zoneUuid,
        installation_offer_uuid: field.proposal.offer_uuid ?? '',
        connected_on: field.proposal.connected_on ?? field.form.today,
        service_plans: field.proposal.service_plans.map((plan) => ({
            service_uuid: plan.service_uuid,
            // Free months count from the connection month, even one that closed before activation.
            free_from: plan.free_months > 0 ? (plan.free_from && plan.free_from > first ? plan.free_from : first) : null,
            free_months: plan.free_months,
        })),
        installation_reason: '',
        note: '',
    };
}

function ActivateModal({ open, onClose, field, zoneUuid }: { open: boolean; onClose: () => void; field: FieldInstallationDetail; zoneUuid: string }) {
    const { data, setData, transform, post, processing, errors } = useForm<ActivationForm>(initialForm(field, zoneUuid));
    const fieldErrors = errors as Record<string, string | undefined>;

    // Picking an offer presets each service's free months (D19); they can still be changed.
    const pickOffer = (uuid: string) => {
        const offer = field.form.offers.find((o) => o.uuid === uuid);
        setData((current) => ({
            ...current,
            installation_offer_uuid: uuid,
            service_plans: offer
                ? current.service_plans.map((plan) => {
                      const months = offer.months.find((m) => m.service_uuid === plan.service_uuid)?.months ?? 0;

                      return { ...plan, free_months: months, free_from: months > 0 ? field.form.installation_month : null };
                  })
                : current.service_plans,
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        transform((values) => ({ ...values, installation_offer_uuid: values.installation_offer_uuid || null }));
        post(`/field-installations/${field.uuid}/activate`, { preserveScroll: true, onSuccess: onClose });
    };

    const beyondCap = data.service_plans.some((plan) => plan.free_months > field.form.max_free_months);

    return (
        <Modal open={open} onClose={onClose} title="Activate this customer">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    Confirm or correct what the agent entered. Free months count from the connection month ({periodLabel(field.form.installation_month)}
                    ); the first bill is never before {periodLabel(field.form.current_month ?? field.form.installation_month)}. The money taken in the field
                    stays as recorded.
                </p>
                <SelectInput id="fi-zone" label="Zone" value={data.zone_uuid} onChange={(e) => setData('zone_uuid', e.target.value)} error={fieldErrors.zone_uuid}>
                    {field.form.zones.map((zone) => (
                        <option key={zone.uuid} value={zone.uuid}>
                            {zone.name}
                        </option>
                    ))}
                </SelectInput>
                <SelectInput
                    id="fi-offer"
                    label="Offer (presets free months)"
                    value={data.installation_offer_uuid}
                    onChange={(e) => pickOffer(e.target.value)}
                    error={fieldErrors.installation_offer_uuid}
                >
                    <option value="">No offer</option>
                    {field.proposal.offer_uuid && !field.form.offers.some((o) => o.uuid === field.proposal.offer_uuid) && (
                        <option value={field.proposal.offer_uuid}>{field.proposal.offer_name ?? 'Agent’s offer'} (no longer available)</option>
                    )}
                    {field.form.offers.map((offer) => (
                        <option key={offer.uuid} value={offer.uuid}>
                            {offer.name}
                        </option>
                    ))}
                </SelectInput>
                <TextInput
                    id="fi-connected"
                    label="Connected on"
                    type="date"
                    max={field.form.today}
                    value={data.connected_on}
                    onChange={(e) => setData('connected_on', e.target.value)}
                    error={fieldErrors.connected_on}
                />
                {data.service_plans.map((plan, index) => {
                    const name = field.proposal.service_plans.find((p) => p.service_uuid === plan.service_uuid)?.service_name ?? 'Service';
                    const update = (patch: Partial<ActivationForm['service_plans'][number]>) =>
                        setData(
                            'service_plans',
                            data.service_plans.map((row, i) => (i === index ? { ...row, ...patch } : row)),
                        );

                    return (
                        <div key={plan.service_uuid} className="grid grid-cols-2 gap-3 rounded-lg border border-slate-100 p-3">
                            <p className="col-span-2 text-sm font-semibold text-slate-800">{name} — free months</p>
                            <TextInput
                                id={`fi-free-${index}`}
                                label="Months"
                                type="number"
                                min="0"
                                max="36"
                                value={String(plan.free_months)}
                                onChange={(e) => {
                                    const months = Math.max(0, Number(e.target.value) || 0);
                                    update({ free_months: months, free_from: months > 0 ? (plan.free_from ?? field.form.installation_month) : null });
                                }}
                            />
                            <TextInput
                                id={`fi-from-${index}`}
                                label="From"
                                type="month"
                                min={field.form.installation_month}
                                disabled={plan.free_months === 0}
                                value={plan.free_from ?? ''}
                                onChange={(e) => update({ free_from: e.target.value || null })}
                            />
                        </div>
                    );
                })}
                {fieldErrors.service_plans && <p className="text-xs text-red-600">{fieldErrors.service_plans}</p>}
                <TextInput
                    id="fi-reason"
                    label={
                        field.form.current_month && data.connected_on.slice(0, 7) < field.form.current_month
                            ? 'Why was this customer registered only now? (connected in an earlier month)'
                            : 'Reason for anything needing approval (free months without the fee fully paid, beyond the promotion or the cap)'
                    }
                    value={data.installation_reason}
                    onChange={(e) => setData('installation_reason', e.target.value)}
                    error={fieldErrors.installation_reason}
                />
                {beyondCap && <p className="text-xs text-amber-700">More than {field.form.max_free_months} free months needs an admin/owner&apos;s approval.</p>}
                <TextInput id="fi-note" label="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} error={fieldErrors.note} />
                {fieldErrors.status && <p className="text-xs text-red-600">{fieldErrors.status}</p>}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        Activate
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function RejectModal({ open, onClose, field }: { open: boolean; onClose: () => void; field: FieldInstallationDetail; customerUuid: string }) {
    const { data, setData, post, processing, errors } = useForm<{ reason: string }>({ reason: '' });
    const taken = field.payments.filter((p) => p.verification_status !== 'rejected').reduce((sum, p) => sum + Number(p.amount), 0);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/field-installations/${field.uuid}/reject`, { onSuccess: onClose });
    };

    return (
        <Modal open={open} onClose={onClose} title="Reject this registration">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    The payments taken in the field are rejected and their receipts voided; the customer is archived.
                    {taken > 0 && ` A refund bon of ${formatCurrency(String(taken))} is raised for approval — the agent still hands the cash over.`}
                </p>
                <TextInput id="fi-reject-reason" label="Reason" required value={data.reason} onChange={(e) => setData('reason', e.target.value)} error={errors.reason} />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="danger" disabled={processing}>
                        Reject
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
