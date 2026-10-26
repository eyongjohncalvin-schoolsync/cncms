import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { IconAlertTriangle, IconGift } from '@tabler/icons-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { PaymentMethodFields } from '@/components/shared/PaymentMethodFields';
import { formatCurrency } from '@/lib/formatCurrency';
import { xsrfTokenHeader } from '@/lib/csrfToken';
import type { CustomerServiceSelectionForm, InstallationOfferOption, InstallationPreview, PaymentMethod, ServiceCatalogueEntry } from '@/types';

/** One ticked service's choice (revision D15): free months from a month, then months paid upfront. */
export interface ServicePlanForm {
    service_uuid: string;
    free_from: string | null;
    free_months: number;
    paid_months: number;
}

export interface InstallationFormValues {
    connected_on: string;
    installation_offer_uuid: string;
    installation_fee: string;
    installation_paid: string;
    installation_reason: string;
    service_plans: ServicePlanForm[];
    method: PaymentMethod;
    method_reference: string;
}

export interface InstallationOptions {
    offers: InstallationOfferOption[];
    max_free_months: number;
    can_manage: boolean;
    can_take_payment: boolean;
    today: string;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const CHIP_COUNT = 6;

/** "2026-10" + n months. */
export function addMonths(period: string, n: number): string {
    const [y, m] = period.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + n, 1));

    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
}

function monthIndex(from: string, period: string): number {
    const [fy, fm] = from.split('-').map(Number);
    const [y, m] = period.split('-').map(Number);

    return (y - fy) * 12 + (m - fm);
}

export function periodLabel(period: string | null): string {
    if (!period) {
        return '—';
    }

    const [y, m] = period.split('-').map(Number);

    return `${MONTHS[m - 1]} ${y}`;
}

/**
 * The connection month: free months count from here, even when it has
 * closed (a late registration) — App\Support\Billing\ConnectionMonth.
 */
export function installationMonth(connectedOn: string, today: string): string {
    return (connectedOn || today).slice(0, 7);
}

/** Month chips from the connection month to six months past the current one. */
function chipCount(firstMonth: string, today: string): number {
    return Math.max(0, monthIndex(firstMonth, today.slice(0, 7))) + CHIP_COUNT;
}

/**
 * Clicking a month chip keeps the free months one consecutive run (D16):
 * extend the run to the month, cut it there, or start it there.
 */
export function toggleFreeMonth(plan: ServicePlanForm, month: string, firstMonth: string): ServicePlanForm {
    const start = plan.free_from ?? firstMonth;

    if (plan.free_months === 0) {
        return { ...plan, free_from: month, free_months: 1 };
    }

    const offset = monthIndex(start, month);

    if (offset < 0) {
        return { ...plan, free_from: month, free_months: plan.free_months - offset };
    }

    if (offset >= plan.free_months) {
        return { ...plan, free_months: offset + 1 };
    }

    if (offset === 0) {
        return plan.free_months === 1 ? { ...plan, free_from: null, free_months: 0 } : { ...plan, free_from: addMonths(start, 1), free_months: plan.free_months - 1 };
    }

    return { ...plan, free_months: offset };
}

/** The plan a newly ticked service starts with: the offer's months, else the installation month free (D16). */
export function defaultPlan(serviceUuid: string, firstMonth: string, offer: InstallationOfferOption | null): ServicePlanForm {
    const preset = offer?.months.find((m) => m.service_uuid === serviceUuid)?.months;
    const months = offer ? (preset ?? 0) : 1;

    return { service_uuid: serviceUuid, free_from: months > 0 ? firstMonth : null, free_months: months, paid_months: 0 };
}

/**
 * The section's starting values: no promotion (free months are the default;
 * a running promotion is picked on purpose — owner, 2026-10-26), nothing
 * paid until typed.
 */
export function initialInstallationValues(options: InstallationOptions): InstallationFormValues {
    return {
        connected_on: options.today,
        installation_offer_uuid: '',
        installation_fee: '',
        installation_paid: '',
        installation_reason: '',
        service_plans: [],
        method: 'cash',
        method_reference: '',
    };
}

/**
 * "Installation & first payment" on the add-customer form
 * (docs/plans/installation-and-promotions.md, phase 2 + revision D15–D19).
 * Per service ticked: ☐ Free months — month chips from the installation
 * month (that month ticked by default), one consecutive run — and ☐ Paid
 * upfront — months that follow the free ones at that service's price. Shows
 * each service's timeline (free → paid → billed), the first billed month
 * and what to collect now, from the server's live preview.
 */
export function InstallationSection({
    options,
    values,
    onChange,
    services,
    catalogue,
    name,
    phone,
    errors,
    onDuplicatesChange,
}: {
    options: InstallationOptions;
    values: InstallationFormValues;
    onChange: <K extends keyof InstallationFormValues>(key: K, value: InstallationFormValues[K]) => void;
    services: CustomerServiceSelectionForm[];
    catalogue: ServiceCatalogueEntry[];
    name: string;
    phone: string;
    errors: Partial<Record<string, string>>;
    onDuplicatesChange: (duplicates: InstallationPreview['duplicates']) => void;
}) {
    const [preview, setPreview] = useState<InstallationPreview | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const latest = useRef(0);

    const offer = options.offers.find((o) => o.uuid === values.installation_offer_uuid) ?? null;
    const firstMonth = installationMonth(values.connected_on, options.today);
    const ticked = catalogue.filter((s) => services.some((row) => row.service_uuid === s.uuid && row.service_variant_uuid === null));
    const tickedKey = ticked.map((s) => s.uuid).join(',');

    // Keep one plan per ticked service; a newly ticked service starts with
    // the offer's months or the installation month free (D16).
    useEffect(() => {
        const plans = ticked.map((service) => values.service_plans.find((p) => p.service_uuid === service.uuid) ?? defaultPlan(service.uuid, firstMonth, offer));
        const same = plans.length === values.service_plans.length && plans.every((p, i) => p === values.service_plans[i]);

        if (!same) {
            onChange('service_plans', plans);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tickedKey]);

    const setPlan = (serviceUuid: string, next: ServicePlanForm) =>
        onChange(
            'service_plans',
            values.service_plans.map((p) => (p.service_uuid === serviceUuid ? next : p)),
        );

    const body = JSON.stringify({
        name,
        phone,
        services: services.map((row) => ({ service_uuid: row.service_uuid, service_variant_uuid: row.service_variant_uuid, price: row.price || '0' })),
        connected_on: values.connected_on || null,
        installation_offer_uuid: values.installation_offer_uuid || null,
        installation_fee: values.installation_fee === '' ? null : values.installation_fee,
        installation_paid: values.installation_paid === '' ? null : values.installation_paid,
        installation_reason: values.installation_reason || null,
        service_plans: values.service_plans,
    });

    useEffect(() => {
        const request = ++latest.current;
        const timer = window.setTimeout(() => {
            fetch('/customers/installation-preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfTokenHeader() },
                body,
            })
                .then(async (response) => {
                    const json = await response.json().catch(() => null);

                    if (!response.ok) {
                        const message = json?.errors ? Object.values(json.errors as Record<string, string[]>).flat().join(' ') : (json?.message ?? 'Preview unavailable.');

                        throw new Error(message);
                    }

                    return json as InstallationPreview;
                })
                .then((result) => {
                    if (request === latest.current) {
                        setPreview(result);
                        setPreviewError(null);
                        onDuplicatesChange(result.duplicates);
                    }
                })
                .catch((error: Error) => {
                    if (request === latest.current) {
                        setPreviewError(error.message);
                    }
                });
        }, 350);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [body]);

    const needsReason = (preview?.needs_approval.length ?? 0) > 0;
    const takesMoney = Number(values.installation_paid) > 0 || values.service_plans.some((p) => p.paid_months > 0);

    return (
        <Card className="animate-fade-up" style={{ animationDelay: '0.15s' }}>
            <CardHeader className="border-b border-slate-100">
                <div className="flex items-center gap-3">
                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                        <IconGift size={18} stroke={1.75} />
                    </span>
                    <div>
                        <h2 className="text-base font-semibold text-slate-900">Installation &amp; first payment</h2>
                        <p className="mt-0.5 text-xs text-slate-500">
                            For each service: free months, then months paid upfront, then the normal bill. Free months apply straight away only when
                            the installation is fully paid today; otherwise an admin/owner approves them.
                        </p>
                    </div>
                </div>
            </CardHeader>
            <CardBody className="space-y-6 p-6">
                <div className="grid gap-6 sm:grid-cols-2">
                    <TextInput
                        id="connected_on"
                        type="date"
                        label="Connected on"
                        value={values.connected_on}
                        max={options.today}
                        onChange={(e) => onChange('connected_on', e.target.value)}
                        error={errors.connected_on}
                    />

                    {options.can_manage && (
                        <SelectInput
                            id="installation_offer_uuid"
                            label="Promotion (if one is running)"
                            value={values.installation_offer_uuid}
                            onChange={(e) => {
                                const next = options.offers.find((o) => o.uuid === e.target.value) ?? null;
                                onChange('installation_offer_uuid', e.target.value);
                                onChange('installation_fee', '');
                                onChange(
                                    'service_plans',
                                    values.service_plans.map((p) => ({ ...defaultPlan(p.service_uuid, firstMonth, next), paid_months: p.paid_months })),
                                );
                            }}
                            error={errors.installation_offer_uuid}
                        >
                            <option value="">No promotion — free months below</option>
                            {options.offers.map((o) => (
                                <option key={o.uuid} value={o.uuid}>
                                    {o.name} — {formatCurrency(o.fee)}
                                </option>
                            ))}
                        </SelectInput>
                    )}

                    {options.can_manage && (
                        <TextInput
                            id="installation_fee"
                            type="number"
                            min="0"
                            label="Installation fee (FCFA)"
                            placeholder={offer ? `${offer.fee} (promotion)` : '0'}
                            value={values.installation_fee}
                            onChange={(e) => onChange('installation_fee', e.target.value)}
                            error={errors.installation_fee}
                        />
                    )}

                    {options.can_manage && options.can_take_payment && (
                        <TextInput
                            id="installation_paid"
                            type="number"
                            min="0"
                            label="Installation paid now (FCFA)"
                            value={values.installation_paid}
                            onChange={(e) => onChange('installation_paid', e.target.value)}
                            error={errors.installation_paid}
                        />
                    )}
                </div>

                {options.can_manage &&
                    ticked.map((service) => {
                        const plan = values.service_plans.find((p) => p.service_uuid === service.uuid) ?? defaultPlan(service.uuid, firstMonth, offer);
                        const timeline = preview?.services.find((t) => t.service_uuid === service.uuid);
                        const start = plan.free_from ?? firstMonth;

                        return (
                            <fieldset key={service.uuid} className="rounded-xl border border-slate-200 p-4">
                                <legend className="px-1 text-sm font-semibold text-slate-800">{service.name}</legend>

                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={plan.free_months > 0}
                                        onChange={(e) =>
                                            setPlan(service.uuid, e.target.checked ? { ...plan, free_from: firstMonth, free_months: 1 } : { ...plan, free_from: null, free_months: 0 })
                                        }
                                        className="h-4 w-4 rounded border-slate-300"
                                    />
                                    Free months
                                </label>
                                {plan.free_months > 0 && (
                                    <div className="mt-2">
                                        <div className="flex flex-wrap gap-1.5" role="group" aria-label={`Free months for ${service.name}`}>
                                            {Array.from({ length: chipCount(firstMonth, options.today) }, (_, i) => addMonths(firstMonth, i)).map((month) => {
                                                const offset = monthIndex(start, month);
                                                const selected = offset >= 0 && offset < plan.free_months;
                                                // A closed month: free if chosen (already used), never billed.
                                                const closed = month < options.today.slice(0, 7);

                                                return (
                                                    <button
                                                        key={month}
                                                        type="button"
                                                        aria-pressed={selected}
                                                        onClick={() => setPlan(service.uuid, toggleFreeMonth(plan, month, firstMonth))}
                                                        title={closed ? 'Already passed' : undefined}
                                                        className={`rounded-full border px-3 py-1 text-xs font-medium ${
                                                            selected ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-slate-600 hover:border-slate-400'
                                                        } ${closed ? 'opacity-60' : ''}`}
                                                    >
                                                        {periodLabel(month)}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        <p className="mt-1 text-xs text-slate-500">
                                            The connection month is free by default — tap the last free month to set them (up to {options.max_free_months}; more
                                            needs an admin/owner). Faded months have already passed.
                                        </p>
                                    </div>
                                )}
                                {/* The free months decide the first bill — they always agree. */}
                                {timeline && (
                                    <p className="mt-2 text-xs text-slate-600">
                                        First bill: <strong>{periodLabel(timeline.first_billed)}</strong>
                                        {timeline.not_billed_from && (
                                            <span className="text-amber-700">
                                                {' '}
                                                · not billed: {periodLabel(timeline.not_billed_from)}
                                                {timeline.not_billed_until !== timeline.not_billed_from ? `–${periodLabel(timeline.not_billed_until ?? null)}` : ''} (passed before
                                                registration — an admin may charge them later)
                                            </span>
                                        )}
                                    </p>
                                )}

                                {options.can_take_payment && (
                                    <div className="mt-3 flex flex-wrap items-center gap-3">
                                        <label className="flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={plan.paid_months > 0}
                                                onChange={(e) => setPlan(service.uuid, { ...plan, paid_months: e.target.checked ? 1 : 0 })}
                                                className="h-4 w-4 rounded border-slate-300"
                                            />
                                            Paid upfront
                                        </label>
                                        {plan.paid_months > 0 && (
                                            <input
                                                type="number"
                                                min={1}
                                                max={24}
                                                aria-label={`Months paid upfront for ${service.name}`}
                                                value={plan.paid_months}
                                                onChange={(e) => setPlan(service.uuid, { ...plan, paid_months: Math.max(0, Math.min(24, Number(e.target.value) || 0)) })}
                                                className="w-20 rounded-lg border border-slate-300 px-2 py-1 text-right text-sm"
                                            />
                                        )}
                                        {plan.paid_months > 0 && timeline?.paid_amount && <span className="text-xs text-slate-500">{formatCurrency(timeline.paid_amount)}</span>}
                                    </div>
                                )}

                                {timeline && (
                                    <p className="mt-3 text-xs text-slate-600">
                                        {timeline.free_from && <>Free {periodLabel(timeline.free_from)}–{periodLabel(timeline.free_until)} → </>}
                                        {timeline.paid_from && <>paid {periodLabel(timeline.paid_from)}–{periodLabel(timeline.paid_until)} → </>}
                                        billed from <strong>{periodLabel(timeline.first_billed)}</strong>
                                    </p>
                                )}
                            </fieldset>
                        );
                    })}
                {errors.service_plans && <p className="text-xs text-red-600">{errors.service_plans}</p>}

                {(needsReason || preview?.late || values.installation_reason !== '') && (
                    <div>
                        <TextInput
                            id="installation_reason"
                            label={needsReason ? 'Reason (needs admin/owner approval)' : 'Why is the customer registered only now?'}
                            value={values.installation_reason}
                            onChange={(e) => onChange('installation_reason', e.target.value)}
                            error={errors.installation_reason}
                            placeholder={
                                preview?.late
                                    ? `Connected in ${preview.connection_month_label} — e.g. the agent connected him and forgot to register him`
                                    : 'Why this customer gets a different deal'
                            }
                        />
                        {preview && preview.needs_approval.length > 0 && (
                            <p className="mt-1 text-xs text-amber-700">Needs approval: {preview.needs_approval.join(', ')}.</p>
                        )}
                    </div>
                )}

                {options.can_take_payment && takesMoney && (
                    <PaymentMethodFields
                        method={values.method}
                        reference={values.method_reference}
                        onMethodChange={(method) => onChange('method', method)}
                        onReferenceChange={(reference) => onChange('method_reference', reference)}
                        methodError={errors.method}
                        referenceError={errors.method_reference}
                    />
                )}

                <div className="rounded-xl border border-indigo-100 bg-indigo-50/60 p-4 text-sm" aria-live="polite">
                    {previewError && <p className="text-red-600">{previewError}</p>}
                    {!previewError && preview && (
                        <div className="space-y-1">
                            <p className="font-semibold text-slate-900">Collect now: {formatCurrency(preview.collect_now)}</p>
                            <p className="text-slate-600">
                                Installation {formatCurrency(preview.paid_now)}
                                {Number(preview.upfront_amount) > 0 ? ` + paid upfront ${formatCurrency(preview.upfront_amount)}` : ''}
                            </p>
                            {Number(preview.installation_balance) > 0 && (
                                <p className="text-slate-600">Installation still owed after today: {formatCurrency(preview.installation_balance)}.</p>
                            )}
                            <p className="text-slate-600">
                                Connected {preview.connection_month_label ?? preview.installation_month_label} · first billed month{' '}
                                <strong>{preview.first_bill_label ?? '—'}</strong>
                            </p>
                            {preview.late && (
                                <p className="text-amber-700">
                                    Registered late: free months count from the connection month; months that passed after them are not billed unless an admin
                                    charges them as old debt.
                                </p>
                            )}
                        </div>
                    )}
                    {!previewError && !preview && <p className="text-slate-500">Working out the first bill…</p>}
                </div>

                {preview && preview.duplicates.length > 0 && (
                    <div className="flex gap-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        <IconAlertTriangle size={18} className="mt-0.5 shrink-0" />
                        <div>
                            <p className="font-medium">This may be an existing customer:</p>
                            <ul className="mt-1 space-y-0.5">
                                {preview.duplicates.map((d) => (
                                    <li key={d.uuid}>
                                        <Link href={`/customers/${d.uuid}`} className="underline">
                                            {d.name}
                                        </Link>{' '}
                                        — {d.phone ?? 'no phone'} · {d.zone ?? 'no zone'} · {d.status}
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-1 text-xs">Promotions are for new installations only.</p>
                        </div>
                    </div>
                )}
            </CardBody>
        </Card>
    );
}
