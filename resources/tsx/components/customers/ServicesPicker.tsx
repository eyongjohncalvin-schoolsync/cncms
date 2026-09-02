import { IconAlertTriangle } from '@tabler/icons-react';
import { formatCurrency } from '@/lib/formatCurrency';
import type { CustomerServiceSelectionForm, ServiceCatalogueEntry } from '@/types';

interface ServicesPickerProps {
    catalogue: ServiceCatalogueEntry[];
    value: CustomerServiceSelectionForm[];
    onChange: (next: CustomerServiceSelectionForm[]) => void;
    error?: string;
}

/**
 * The customer add/edit form's "Services" block (services.md sections 6-8)
 * — replaces the old single Monthly Bill input. Every active catalogue
 * service is a tickable row; ticking one reveals a price input (prefilled
 * from the catalogue price) and, if that service offers any options
 * ("channels" — services.md section 4), a nested tick-list of those, each
 * its own price line. The total at the bottom IS what `customers.bill`
 * becomes — computed here purely for display, the real sum is
 * authoritatively recomputed server-side by
 * App\Services\CustomerSubscriptionService::recomputeBill().
 *
 * `value` is the exact shape the server expects: one entry per ticked
 * service, one more per ticked option, `service_variant_uuid: null` marking
 * the base row. Untick a service and every option entry under it is
 * dropped in the same update — a customer can't hold "the news channel
 * option" without the base service (CustomerSubscriptionService enforces
 * this too; this UI just never lets you build an invalid selection).
 */
export function ServicesPicker({ catalogue, value, onChange, error }: ServicesPickerProps) {
    const baseSelection = (serviceUuid: string) => value.find((v) => v.service_uuid === serviceUuid && v.service_variant_uuid === null);
    const variantSelection = (variantUuid: string) => value.find((v) => v.service_variant_uuid === variantUuid);

    function toggleService(service: ServiceCatalogueEntry, checked: boolean) {
        if (checked) {
            onChange([...value, { service_uuid: service.uuid, service_variant_uuid: null, price: service.price }]);

            return;
        }

        // Unticking the base service drops it AND every option ticked
        // under it — an option can't outlive its base subscription.
        onChange(value.filter((v) => v.service_uuid !== service.uuid));
    }

    function toggleVariant(service: ServiceCatalogueEntry, variantUuid: string, price: string, checked: boolean) {
        if (checked) {
            onChange([...value, { service_uuid: service.uuid, service_variant_uuid: variantUuid, price }]);

            return;
        }

        onChange(value.filter((v) => v.service_variant_uuid !== variantUuid));
    }

    function setPrice(match: (v: CustomerServiceSelectionForm) => boolean, price: string) {
        onChange(value.map((v) => (match(v) ? { ...v, price } : v)));
    }

    const total = value.reduce((sum, v) => sum + (parseFloat(v.price) || 0), 0);

    return (
        <div className="space-y-3">
            <div className="space-y-2">
                {catalogue.map((service) => {
                    const selection = baseSelection(service.uuid);
                    const checked = selection !== undefined;
                    const activeVariants = service.variants.filter((v) => v.active || variantSelection(v.uuid) !== undefined);

                    return (
                        <div
                            key={service.uuid}
                            className={`rounded-xl border p-4 transition-colors ${checked ? 'border-indigo-200 bg-indigo-50/40' : 'border-slate-200 bg-white'}`}
                        >
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    checked={checked}
                                    onChange={(e) => toggleService(service, e.target.checked)}
                                    className="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <span className="text-sm font-semibold text-slate-900">{service.name}</span>
                                        {!service.active && (
                                            <span className="rounded-full bg-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">inactive</span>
                                        )}
                                    </span>
                                    {service.description && <span className="mt-0.5 block text-xs text-slate-500">{service.description}</span>}
                                </span>
                                {checked && (
                                    <span className="flex shrink-0 items-center gap-1">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={selection.price}
                                            onClick={(e) => e.preventDefault()}
                                            onChange={(e) => setPrice((v) => v.service_uuid === service.uuid && v.service_variant_uuid === null, e.target.value)}
                                            className="w-28 rounded-lg border border-slate-300 px-2.5 py-1.5 text-right text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                        <span className="text-xs text-slate-400">FCFA</span>
                                    </span>
                                )}
                            </label>

                            {checked && parseFloat(selection.price) === 0 && (
                                <p className="mt-2 ml-7 flex items-center gap-1 text-xs text-amber-600">
                                    <IconAlertTriangle size={12} stroke={1.75} />
                                    No price set for this service yet — set one in Settings &rarr; Services.
                                </p>
                            )}

                            {checked && activeVariants.length > 0 && (
                                <div className="mt-3 ml-7 space-y-2 border-l-2 border-indigo-100 pl-3">
                                    {activeVariants.map((variant) => {
                                        const vSelection = variantSelection(variant.uuid);
                                        const vChecked = vSelection !== undefined;

                                        return (
                                            <div key={variant.uuid}>
                                                <label className="flex cursor-pointer items-center gap-2.5">
                                                    <input
                                                        type="checkbox"
                                                        checked={vChecked}
                                                        onChange={(e) => toggleVariant(service, variant.uuid, variant.price, e.target.checked)}
                                                        className="h-3.5 w-3.5 shrink-0 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                    <span className="flex min-w-0 flex-1 items-center gap-1.5 text-sm text-slate-700">
                                                        {variant.name}
                                                        {!variant.active && (
                                                            <span className="rounded-full bg-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">inactive</span>
                                                        )}
                                                    </span>
                                                    {vChecked && (
                                                        <span className="flex shrink-0 items-center gap-1">
                                                            <input
                                                                type="number"
                                                                step="0.01"
                                                                min="0"
                                                                value={vSelection.price}
                                                                onClick={(e) => e.preventDefault()}
                                                                onChange={(e) => setPrice((v) => v.service_variant_uuid === variant.uuid, e.target.value)}
                                                                className="w-24 rounded-lg border border-slate-300 px-2 py-1 text-right text-xs text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                                            />
                                                            <span className="text-xs text-slate-400">FCFA</span>
                                                        </span>
                                                    )}
                                                </label>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>

            {error && <p className="text-xs text-red-600">{error}</p>}

            <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                <span className="text-sm font-medium text-slate-600">Total monthly bill</span>
                <span className="text-base font-semibold text-slate-900">{formatCurrency(total)}</span>
            </div>
        </div>
    );
}
