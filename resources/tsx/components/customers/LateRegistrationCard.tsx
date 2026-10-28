import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { IconClockExclamation } from '@tabler/icons-react';
import { Card, CardBody } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { formatCurrency } from '@/lib/formatCurrency';
import { formatMonthYear } from '@/lib/formatMonthYear';
import type { LateRegistration } from '@/types';

/**
 * A customer registered after their connection month closed
 * (LateRegistrationService): free months counted from the connection, the
 * months that passed unbilled, and "Charge as old debt" — an arrears
 * adjustment on the current bill, confirmed by another approver. Owner,
 * 2026-10-28: the date and reason can be corrected (the registration month
 * undoes it), and the charge withdrawn, decided here, or reversed.
 */
export function LateRegistrationCard({ customerUuid, late }: { customerUuid: string; late: LateRegistration }) {
    const [open, setOpen] = useState<'charge' | 'correct' | 'reverse' | 'says_paid' | null>(null);
    const free = late.months.filter((m) => m.free).map((m) => m.period);
    const missed = late.months.filter((m) => !m.free).map((m) => m.period);

    return (
        <Card className="border-amber-200">
            <CardBody className="flex flex-col gap-2 text-sm">
                <div className="flex items-start gap-3">
                    <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                        <IconClockExclamation size={18} stroke={1.75} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="font-semibold text-slate-900">
                            Registered late — connected {formatMonthYear(late.connection_month)}, first bill {formatMonthYear(late.billing_starts)}
                        </p>
                        {late.reason && <p className="text-slate-600">Why: {late.reason}</p>}
                        {late.connected_by && <p className="text-slate-600">Connected by: {late.connected_by}</p>}
                        {late.says_paid_amount !== null &&
                            (Number(late.says_paid_amount) > 0 ? (
                                late.says_paid_settled ? (
                                    <p className="text-slate-600">
                                        The customer said they paid {formatCurrency(late.says_paid_amount)} to {late.says_paid_to ?? 'someone'} —{' '}
                                        {SAYS_PAID_OUTCOMES[late.says_paid_settled.outcome] ?? late.says_paid_settled.outcome}
                                        {late.says_paid_settled.by ? ` (${late.says_paid_settled.by})` : ''}: {late.says_paid_settled.note}
                                    </p>
                                ) : (
                                    <p className="font-medium text-red-700">
                                        The customer says they paid {formatCurrency(late.says_paid_amount)} to {late.says_paid_to ?? 'someone'} — check where that money
                                        went, then settle it.
                                    </p>
                                )
                            ) : (
                                <p className="text-slate-600">The customer says they paid nobody.</p>
                            ))}
                        <p className="text-slate-600">
                            {free.length > 0 && <>Free: {range(free)}. </>}
                            {missed.length > 0 ? (
                                <>
                                    Not billed: {range(missed)} ({missed.length} month{missed.length === 1 ? '' : 's'} = {formatCurrency(late.missed_amount)}).
                                </>
                            ) : (
                                'Every month before the first bill was free.'
                            )}
                        </p>
                        {late.charge && (
                            <p className="mt-1 text-slate-700">
                                Charged as old debt: {formatCurrency(late.charge.amount)} — {late.charge.status.replaceAll('_', ' ')}
                                {late.charge.cancels_free_months ? ' (free months end on approval)' : ''}
                                {late.charge.reversed && ' — reversed (credited back)'}
                                {!late.charge.reversed && late.charge.reversal_status && ` — reversal ${late.charge.reversal_status.replaceAll('_', ' ')}`}.
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 flex-col items-end gap-1.5">
                        {late.can_charge && (
                            <Button type="button" variant="secondary" onClick={() => setOpen('charge')}>
                                Charge as old debt
                            </Button>
                        )}
                        {late.can_correct && (
                            <Button type="button" variant="secondary" onClick={() => setOpen('correct')}>
                                Correct date
                            </Button>
                        )}
                        {late.can_decide && late.charge && (
                            <span className="flex gap-1.5">
                                <Button type="button" onClick={() => router.post(`/arrears-adjustments/${late.charge!.uuid}/approve`, {}, { preserveScroll: true })}>
                                    Approve charge
                                </Button>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => {
                                        const reason = prompt('Why reject this charge?');

                                        if (reason && reason.trim().length > 0) {
                                            router.post(`/arrears-adjustments/${late.charge!.uuid}/reject`, { rejection_reason: reason }, { preserveScroll: true });
                                        }
                                    }}
                                >
                                    Reject
                                </Button>
                            </span>
                        )}
                        {late.can_withdraw && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => confirm('Withdraw your old-debt charge request?') && router.post(`/customers/${customerUuid}/late-registration/withdraw`, {}, { preserveScroll: true })}
                            >
                                Withdraw charge
                            </Button>
                        )}
                        {late.can_reverse && (
                            <Button type="button" variant="secondary" onClick={() => setOpen('reverse')}>
                                Reverse charge
                            </Button>
                        )}
                        {late.can_settle_says_paid && (
                            <Button type="button" variant="secondary" onClick={() => setOpen('says_paid')}>
                                Settle what they paid
                            </Button>
                        )}
                    </div>
                </div>
            </CardBody>
            {open === 'charge' && <ChargeModal customerUuid={customerUuid} late={late} onClose={() => setOpen(null)} />}
            {open === 'correct' && <CorrectModal customerUuid={customerUuid} late={late} onClose={() => setOpen(null)} />}
            {open === 'reverse' && <ReverseModal customerUuid={customerUuid} late={late} onClose={() => setOpen(null)} />}
            {open === 'says_paid' && <SaysPaidModal customerUuid={customerUuid} late={late} onClose={() => setOpen(null)} />}
        </Card>
    );
}

/** The connection date (and why) corrected; the registration month undoes the late registration. */
function CorrectModal({ customerUuid, late, onClose }: { customerUuid: string; late: LateRegistration; onClose: () => void }) {
    const { data, setData, patch, processing, errors } = useForm({ connected_on: late.connected_on ?? '', reason: late.reason ?? '' });
    const stillLate = data.connected_on !== '' && data.connected_on.slice(0, 7) < late.registered_in;

    function submit(e: FormEvent) {
        e.preventDefault();
        patch(`/customers/${customerUuid}/late-registration`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal open onClose={onClose} title="Correct the connection date">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    The free months move with the connection and billing starts from it (or from {formatMonthYear(late.registered_in)}, when they were
                    registered). A date in {formatMonthYear(late.registered_in)} means they were not registered late. Closed months are never rewritten.
                </p>
                <TextInput
                    id="late-connected-on"
                    type="date"
                    label="Connected on"
                    required
                    value={data.connected_on}
                    onChange={(e) => setData('connected_on', e.target.value)}
                    error={errors.connected_on}
                />
                {stillLate && (
                    <TextInput
                        id="late-reason"
                        label="Why they were registered late"
                        required
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                        error={errors.reason}
                    />
                )}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || data.connected_on === ''}>
                        {stillLate ? 'Save' : 'Not a late registration'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

/** Undo an approved old-debt charge: an equal credit, approved by someone else. */
function ReverseModal({ customerUuid, late, onClose }: { customerUuid: string; late: LateRegistration; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ note: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/customers/${customerUuid}/late-registration/reverse`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal open onClose={onClose} title="Reverse the old-debt charge">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    {formatCurrency(late.charge?.amount ?? '0')} is credited back on this month&apos;s bill once another approver confirms it
                    {late.charge?.cancels_free_months ? ', and the free months it ended come back' : ''}. The charge itself stays in the record.
                </p>
                <TextInput id="late-reverse-note" label="Why" required value={data.note} onChange={(e) => setData('note', e.target.value)} error={errors.note ?? (errors as Record<string, string | undefined>).charge} />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || data.note.trim().length < 3}>
                        Ask for approval
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

const SAYS_PAID_OUTCOMES: Record<string, string> = {
    found: 'the money came in',
    mistaken: 'nobody was paid (customer mistaken)',
    written_off: 'lost, written off',
};

/** What became of the money the customer says they paid someone (LateRegistrationService::settleSaysPaid()). */
function SaysPaidModal({ customerUuid, late, onClose }: { customerUuid: string; late: LateRegistration; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ outcome: 'found', note: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/customers/${customerUuid}/late-registration/says-paid`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal open onClose={onClose} title="What became of the money?">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    The customer says they paid {formatCurrency(late.says_paid_amount ?? '0')} to {late.says_paid_to ?? 'someone'}. Once settled it leaves the cash
                    alerts; the answer stays on this card.
                </p>
                <fieldset className="flex flex-col gap-2">
                    {Object.entries(SAYS_PAID_OUTCOMES).map(([value, label]) => (
                        <label key={value} className={`flex cursor-pointer gap-3 rounded-lg border p-3 text-sm ${data.outcome === value ? 'border-blue-500 bg-blue-50' : 'border-slate-200'}`}>
                            <input type="radio" name="outcome" checked={data.outcome === value} onChange={() => setData('outcome', value)} />
                            <span className="first-letter:uppercase">{label}</span>
                        </label>
                    ))}
                </fieldset>
                <TextInput
                    id="late-says-paid-note"
                    label={data.outcome === 'found' ? 'Where it came in (receipt, handover…)' : 'What you found'}
                    required
                    value={data.note}
                    onChange={(e) => setData('note', e.target.value)}
                    error={errors.note ?? errors.outcome}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant={data.outcome === 'written_off' ? 'warning' : 'primary'} disabled={processing || data.note.trim().length < 3}>
                        Settle
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function ChargeModal({ customerUuid, late, onClose }: { customerUuid: string; late: LateRegistration; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        scope: (late.missed_months > 0 ? 'missed' : 'all') as 'missed' | 'all',
        amount: String(Math.round(Number(late.missed_months > 0 ? late.missed_amount : late.all_amount))),
        note: '',
    });

    function choose(scope: 'missed' | 'all') {
        setData((current) => ({ ...current, scope, amount: String(Math.round(Number(scope === 'missed' ? late.missed_amount : late.all_amount))) }));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/customers/${customerUuid}/late-registration/charge`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal open onClose={onClose} title="Charge the late months as old debt">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <p className="text-sm text-slate-600">
                    Added to this month&apos;s bill as old debt once another approver confirms it (Arrears Adjustments). Months already closed are never
                    rewritten.
                </p>
                <fieldset className="flex flex-col gap-2">
                    {late.missed_months > 0 && (
                        <Choice
                            checked={data.scope === 'missed'}
                            onChange={() => choose('missed')}
                            label={`Only the months not billed — ${late.missed_months} month${late.missed_months === 1 ? '' : 's'}, ${formatCurrency(late.missed_amount)}`}
                            hint="The free months stay as given."
                        />
                    )}
                    <Choice
                        checked={data.scope === 'all'}
                        onChange={() => choose('all')}
                        label={`Every month since connection — ${late.all_months} month${late.all_months === 1 ? '' : 's'}, ${formatCurrency(late.all_amount)}`}
                        hint={
                            late.free_months_running
                                ? 'No free months: the ones still to come end too, and are billed normally.'
                                : 'No free months: the ones already used are charged.'
                        }
                    />
                </fieldset>
                <TextInput
                    id="late-amount"
                    label="Amount (FCFA) — you may lower it"
                    type="number"
                    min="1"
                    value={data.amount}
                    onChange={(e) => setData('amount', e.target.value)}
                    error={errors.amount}
                />
                <TextInput
                    id="late-note"
                    label="Why"
                    required
                    placeholder="e.g. The customer knew he was not registered and paid nobody"
                    value={data.note}
                    onChange={(e) => setData('note', e.target.value)}
                    error={errors.note}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || data.note.trim().length < 3}>
                        Ask for approval
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function Choice({ checked, onChange, label, hint }: { checked: boolean; onChange: () => void; label: string; hint: string }) {
    return (
        <label className={`flex cursor-pointer gap-3 rounded-lg border p-3 ${checked ? 'border-blue-500 bg-blue-50' : 'border-slate-200'}`}>
            <input type="radio" className="mt-1" checked={checked} onChange={onChange} />
            <span>
                <span className="block text-sm font-medium text-slate-900">{label}</span>
                <span className="block text-xs text-slate-500">{hint}</span>
            </span>
        </label>
    );
}

/** "Aug–Sep 2026", or "Aug 2026". */
function range(periods: string[]): string {
    if (periods.length === 1) {
        return formatMonthYear(periods[0]);
    }

    return `${formatMonthYear(periods[0])}–${formatMonthYear(periods[periods.length - 1])}`;
}
