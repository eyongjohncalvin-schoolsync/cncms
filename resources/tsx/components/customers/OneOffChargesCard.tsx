import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { IconHomeMove } from '@tabler/icons-react';
import { Card, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { EmptyState } from '@/components/ui/EmptyState';
import { ChargeRow, METHODS, PayModal, RefundModal, WaiverModal } from '@/components/customers/InstallationCard';
import { formatCurrency } from '@/lib/formatCurrency';
import type { CustomerCharge, CustomerOneOffCharges, PaymentMethod } from '@/types';

/**
 * Relocation (the customer moved house) and re-installation (installed again at
 * the same address) fees on the customer page — the installation charge model
 * with another kind (docs/plans/installation-and-promotions.md, "Out of scope /
 * later"). A separate balance: its own line on the bill, never part of the
 * monthly total or arrears, never a reason to disconnect. Instalments, waivers,
 * lower-fee approval and refunds work exactly like an installation fee's.
 */
type Dialog =
    | { kind: 'relocation' | 'reinstallation' }
    | { kind: 'pay'; charge: CustomerCharge }
    | { kind: 'waive'; charge: CustomerCharge }
    | { kind: 'refund'; charge: CustomerCharge }
    | null;

interface Props {
    customerUuid: string;
    zoneUuid: string | null;
    location: string | null;
    oneOff: CustomerOneOffCharges;
}

export function OneOffChargesCard({ customerUuid, zoneUuid, location, oneOff }: Props) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const close = () => setDialog(null);
    const openKinds = new Set(oneOff.charges.filter((charge) => charge.status === 'open').map((charge) => charge.kind));

    return (
        <Card className="p-0">
            <CardHeader className="border-b border-slate-100">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-sky-50 text-sky-600">
                            <IconHomeMove size={18} stroke={1.75} />
                        </span>
                        <div>
                            <h3 className="text-base font-semibold text-slate-900">Relocation &amp; re-installation</h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Balance still owed: <span className="font-semibold text-slate-800">{formatCurrency(oneOff.balance)}</span> — separate from
                                the monthly bill, never counted as arrears
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {oneOff.can_relocate && !openKinds.has('relocation') && (
                            <Button type="button" variant="secondary" onClick={() => setDialog({ kind: 'relocation' })}>
                                Relocate
                            </Button>
                        )}
                        {oneOff.can_reinstall && !openKinds.has('reinstallation') && (
                            <Button type="button" variant="secondary" onClick={() => setDialog({ kind: 'reinstallation' })}>
                                Re-install
                            </Button>
                        )}
                    </div>
                </div>
            </CardHeader>

            {oneOff.charges.length === 0 ? (
                <div className="p-4">
                    <EmptyState title="No relocation or re-installation on file" />
                </div>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {oneOff.charges.map((charge) => (
                        <ChargeRow
                            key={charge.uuid}
                            charge={charge}
                            onPay={() => setDialog({ kind: 'pay', charge })}
                            onWaive={() => setDialog({ kind: 'waive', charge })}
                            onRefund={() => setDialog({ kind: 'refund', charge })}
                        />
                    ))}
                </ul>
            )}

            <OneOffModal
                kind={dialog?.kind === 'relocation' || dialog?.kind === 'reinstallation' ? dialog.kind : null}
                onClose={close}
                customerUuid={customerUuid}
                zoneUuid={zoneUuid}
                location={location}
                oneOff={oneOff}
            />
            <PayModal charge={dialog?.kind === 'pay' ? dialog.charge : null} onClose={close} />
            <WaiverModal charge={dialog?.kind === 'waive' ? dialog.charge : null} onClose={close} />
            <RefundModal charge={dialog?.kind === 'refund' ? dialog.charge : null} onClose={close} />
        </Card>
    );
}

interface OneOffForm {
    fee: string;
    paid_now: string;
    method: PaymentMethod;
    method_reference: string;
    reason: string;
    note: string;
    zone_uuid: string;
    location: string;
}

function OneOffModal({
    kind,
    onClose,
    customerUuid,
    zoneUuid,
    location,
    oneOff,
}: {
    kind: 'relocation' | 'reinstallation' | null;
    onClose: () => void;
    customerUuid: string;
    zoneUuid: string | null;
    location: string | null;
    oneOff: CustomerOneOffCharges;
}) {
    const isRelocation = kind === 'relocation';
    const standard = kind ? oneOff.default_fees[kind] : null;
    const { data, setData, post, processing, errors, reset, transform } = useForm<OneOffForm>({
        fee: '',
        paid_now: '',
        method: 'cash',
        method_reference: '',
        reason: '',
        note: '',
        zone_uuid: zoneUuid ?? '',
        location: '',
    });

    const fee = data.fee === '' ? (standard ?? '') : data.fee;
    const needsApproval = standard !== null && fee !== '' && Number(fee) < Number(standard);
    const waiving = standard !== null && fee !== '' && Number(fee) === 0;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!kind) return;

        transform((form) => ({
            fee: form.fee === '' ? null : form.fee,
            paid_now: form.paid_now === '' ? 0 : form.paid_now,
            method: form.method,
            method_reference: form.method_reference,
            reason: form.reason,
            note: form.note,
            ...(isRelocation ? { location: form.location, zone_uuid: form.zone_uuid || null } : {}),
        }));
        post(`/customers/${customerUuid}/${isRelocation ? 'relocation' : 'reinstallation'}`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Modal open={kind !== null} onClose={onClose} title={isRelocation ? 'Relocate customer' : 'Re-install'}>
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    {isRelocation
                        ? `Same account and history at a new address${location ? ` (now: ${location})` : ''}. `
                        : 'Installed again at the same address. '}
                    The fee is owed separately from the monthly bill, may be paid in instalments, and comes with no free months.
                </p>

                {isRelocation && (
                    <>
                        <TextInput id="oneoff-location" label="New address" required maxLength={30} value={data.location} onChange={(e) => setData('location', e.target.value)} error={errors.location} />
                        <SelectInput id="oneoff-zone" label="Zone" value={data.zone_uuid} onChange={(e) => setData('zone_uuid', e.target.value)} error={errors.zone_uuid}>
                            {oneOff.zones.map((zone) => (
                                <option key={zone.uuid} value={zone.uuid}>
                                    {zone.name}
                                    {zone.branch_name ? ` — ${zone.branch_name}` : ''}
                                </option>
                            ))}
                        </SelectInput>
                    </>
                )}

                <TextInput
                    id="oneoff-fee"
                    label={standard !== null ? `Fee (FCFA) — standard ${formatCurrency(standard)}` : 'Fee (FCFA)'}
                    type="number"
                    min="0"
                    step="0.01"
                    required={standard === null}
                    placeholder={standard ?? ''}
                    value={data.fee}
                    onChange={(e) => setData('fee', e.target.value)}
                    error={errors.fee}
                />
                {(needsApproval || waiving) && (
                    <TextInput
                        id="oneoff-reason"
                        label={waiving ? 'Why is the fee waived? (needs approval)' : 'Why is the fee lower? (needs approval)'}
                        required
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                        error={errors.reason}
                    />
                )}

                {oneOff.can_record_payment && !waiving && (
                    <>
                        <TextInput
                            id="oneoff-paid"
                            label="Paid now (FCFA)"
                            type="number"
                            min="0"
                            step="0.01"
                            max={fee || undefined}
                            value={data.paid_now}
                            onChange={(e) => setData('paid_now', e.target.value)}
                            error={errors.paid_now}
                        />
                        {Number(data.paid_now) > 0 && (
                            <>
                                <SelectInput id="oneoff-method" label="Paid by" value={data.method} onChange={(e) => setData('method', e.target.value as PaymentMethod)} error={errors.method}>
                                    {METHODS.map((method) => (
                                        <option key={method.value} value={method.value}>
                                            {method.label}
                                        </option>
                                    ))}
                                </SelectInput>
                                {data.method !== 'cash' && (
                                    <TextInput id="oneoff-reference" label="Transaction ID" value={data.method_reference} onChange={(e) => setData('method_reference', e.target.value)} error={errors.method_reference} />
                                )}
                            </>
                        )}
                    </>
                )}

                <TextInput id="oneoff-note" label="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} error={errors.note} />

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {isRelocation ? 'Relocate' : 'Record re-installation'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
