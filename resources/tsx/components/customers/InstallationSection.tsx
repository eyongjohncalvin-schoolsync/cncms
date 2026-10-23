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

export interface InstallationFormValues {
    connected_on: string;
    installation_offer_uuid: string;
    installation_fee: string;
    installation_paid: string;
    installation_reason: string;
    free_months: { service_uuid: string; months: number }[] | null;
    advance_months: number;
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

/** The section's starting values: the default offer pre-picked, nothing paid until typed. */
export function initialInstallationValues(options: InstallationOptions): InstallationFormValues {
    const offer = options.can_manage ? options.offers.find((o) => o.is_default) : undefined;

    return {
        connected_on: options.today,
        installation_offer_uuid: offer?.uuid ?? '',
        installation_fee: '',
        installation_paid: '',
        installation_reason: '',
        free_months: null,
        advance_months: 0,
        method: 'cash',
        method_reference: '',
    };
}

/**
 * "Installation & first payment" on the add-customer form
 * (docs/plans/installation-and-promotions.md, phase 2, item 5): the offer,
 * its fee (an override needs a reason and approval), what is paid now, an
 * advance in months, the connection date and the free months per ticked
 * service — with the server's live preview line ("Collect now: 17,500 · TV
 * free Nov–Dec · first bill Jan 2027"), the advance amount and a warning
 * when the customer may already exist.
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
    const tickedServices = catalogue.filter((s) => services.some((row) => row.service_uuid === s.uuid && row.service_variant_uuid === null));

    // Free months shown per ticked service: the edited values, else the offer's.
    const monthsFor = (serviceUuid: string): number => {
        const edited = values.free_months?.find((row) => row.service_uuid === serviceUuid);

        if (edited) {
            return edited.months;
        }

        return offer?.months.find((m) => m.service_uuid === serviceUuid)?.months ?? 0;
    };

    const body = JSON.stringify({
        name,
        phone,
        services: services.map((row) => ({ service_uuid: row.service_uuid, service_variant_uuid: row.service_variant_uuid, price: row.price || '0' })),
        connected_on: values.connected_on || null,
        installation_offer_uuid: values.installation_offer_uuid || null,
        installation_fee: values.installation_fee === '' ? null : values.installation_fee,
        installation_paid: values.installation_paid === '' ? null : values.installation_paid,
        installation_reason: values.installation_reason || null,
        free_months: values.free_months,
        advance_months: values.advance_months || 0,
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
                            The month the customer joins is not billed. Free months start the month after the installation is fully paid.
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
                            label="Installation offer"
                            value={values.installation_offer_uuid}
                            onChange={(e) => {
                                onChange('installation_offer_uuid', e.target.value);
                                onChange('free_months', null);
                                onChange('installation_fee', '');
                                onChange('installation_paid', '');
                            }}
                            error={errors.installation_offer_uuid}
                        >
                            <option value="">No offer</option>
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
                            placeholder={offer ? `${offer.fee} (offer)` : '0'}
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

                    {options.can_take_payment && (
                        <TextInput
                            id="advance_months"
                            type="number"
                            min="0"
                            max="24"
                            label="Months paid in advance"
                            value={String(values.advance_months)}
                            onChange={(e) => onChange('advance_months', Math.max(0, Number(e.target.value)))}
                            error={errors.advance_months}
                        />
                    )}
                </div>

                {options.can_manage && tickedServices.length > 0 && (
                    <fieldset>
                        <legend className="mb-1.5 text-sm font-medium text-slate-700">Free months per service (up to {options.max_free_months})</legend>
                        <div className="space-y-2">
                            {tickedServices.map((service) => (
                                <div key={service.uuid} className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
                                    <span className="text-sm text-slate-700">{service.name}</span>
                                    <input
                                        type="number"
                                        min={0}
                                        max={options.max_free_months}
                                        aria-label={`Free months for ${service.name}`}
                                        value={monthsFor(service.uuid)}
                                        onChange={(e) => {
                                            const rows = tickedServices.map((s) => ({
                                                service_uuid: s.uuid,
                                                months: s.uuid === service.uuid ? Math.max(0, Number(e.target.value)) : monthsFor(s.uuid),
                                            }));
                                            onChange('free_months', rows);
                                        }}
                                        className="w-20 rounded-lg border border-slate-300 px-2 py-1 text-right text-sm"
                                    />
                                </div>
                            ))}
                        </div>
                        {errors.free_months && <p className="mt-1 text-xs text-red-600">{errors.free_months}</p>}
                    </fieldset>
                )}

                {(needsReason || values.installation_reason !== '') && (
                    <div>
                        <TextInput
                            id="installation_reason"
                            label="Reason (needs admin/owner approval)"
                            value={values.installation_reason}
                            onChange={(e) => onChange('installation_reason', e.target.value)}
                            error={errors.installation_reason}
                            placeholder="Why this customer gets a different deal"
                        />
                        {preview && preview.needs_approval.length > 0 && (
                            <p className="mt-1 text-xs text-amber-700">Needs approval: {preview.needs_approval.join(', ')}.</p>
                        )}
                    </div>
                )}

                {options.can_take_payment && (bnum(values.installation_paid) > 0 || values.advance_months > 0) && (
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
                            <p className="font-semibold text-slate-900">{preview.line}</p>
                            {preview.advance && (
                                <p className="text-slate-600">
                                    Advance: {formatCurrency(preview.advance.amount)} for {preview.advance.months} month
                                    {preview.advance.months === 1 ? '' : 's'}
                                    {preview.advance.from_label ? ` (${preview.advance.from_label} – ${preview.advance.until_label})` : ''}
                                </p>
                            )}
                            {bnum(preview.installation_balance) > 0 && (
                                <p className="text-slate-600">
                                    Installation still owed after today: {formatCurrency(preview.installation_balance)} — billed normally until it is paid.
                                </p>
                            )}
                            <p className="text-xs text-slate-500">First billed month: {preview.billing_starts_label}</p>
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
                            <p className="mt-1 text-xs">Installation offers are for new installations only.</p>
                        </div>
                    </div>
                )}
            </CardBody>
        </Card>
    );
}

function bnum(value: string | null | undefined): number {
    const n = Number(value);

    return Number.isFinite(n) ? n : 0;
}
