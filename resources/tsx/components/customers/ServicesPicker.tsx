import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { IconAlertTriangle } from '@tabler/icons-react';
import { formatCurrency } from '@/lib/formatCurrency';
import { hasPermission } from '@/lib/permissions';
import type { CustomerServiceSelectionForm, PageProps, ServiceCatalogueEntry } from '@/types';

/** Same "2000.00" shape the server compares with. */
function samePrice(a: string | undefined, b: string | undefined): boolean {
    return a !== undefined && b !== undefined && (parseFloat(a) || 0).toFixed(2) === (parseFloat(b) || 0).toFixed(2);
}

/**
 * A ticked service's price quick picks (owner, 2026-10-27): its default,
 * its price options, and — when editing — the customer's current price.
 * With `customers.custom_price` the amount box above takes any amount at
 * or above the minimum; without it these are the only choices.
 */
function PriceChoices({
    service,
    price,
    currentPrice,
    mayTypePrice,
    onPick,
}: {
    service: ServiceCatalogueEntry;
    price: string;
    currentPrice: string | undefined;
    mayTypePrice: boolean;
    onPick: (price: string) => void;
}) {
    const choices: { amount: string; label: string }[] = [{ amount: service.price, label: 'Default' }];

    for (const option of service.price_options ?? []) {
        if (!choices.some((choice) => samePrice(choice.amount, option.amount))) {
            choices.push({ amount: option.amount, label: option.label ?? '' });
        }
    }

    if (currentPrice !== undefined && !choices.some((choice) => samePrice(choice.amount, currentPrice))) {
        choices.push({ amount: currentPrice, label: 'Current' });
    }

    if (choices.length < 2 && !service.min_price) {
        return null;
    }

    return (
        <div className="mt-2 ml-7 flex flex-wrap items-center gap-1.5">
            {choices.map((choice) => {
                const active = samePrice(choice.amount, price);

                return (
                    <button
                        key={choice.amount}
                        type="button"
                        onClick={() => onPick(choice.amount)}
                        className={`rounded-full border px-2.5 py-1 text-xs font-medium transition-colors ${
                            active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-indigo-400'
                        }`}
                    >
                        {formatCurrency(parseFloat(choice.amount) || 0)}
                        {choice.label && <span className={active ? 'text-indigo-100' : 'text-slate-400'}> · {choice.label}</span>}
                    </button>
                );
            })}
            {mayTypePrice && service.min_price && (
                <span className="text-xs text-slate-400">Other amounts: at least {formatCurrency(parseFloat(service.min_price) || 0)}</span>
            )}
        </div>
    );
}

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
    // Typing a price of one's own is `customers.custom_price`; everyone else
    // picks the default or a price option (the server checks the same).
    const { auth } = usePage<PageProps>().props;
    const mayTypePrice = hasPermission(auth.user?.permissions, 'customers.custom_price');
    // An existing customer's current prices — kept as a choice when editing.
    const [initialPrices] = useState<Record<string, string>>(() =>
        Object.fromEntries(value.filter((v) => v.service_variant_uuid === null).map((v) => [v.service_uuid, v.price])),
    );

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
                                {checked && mayTypePrice && (
                                    <span className="flex shrink-0 items-center gap-1">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min={service.min_price ?? '0'}
                                            value={selection.price}
                                            aria-label={`${service.name} price`}
                                            onClick={(e) => e.preventDefault()}
                                            onChange={(e) => setPrice((v) => v.service_uuid === service.uuid && v.service_variant_uuid === null, e.target.value)}
                                            className="w-28 rounded-lg border border-slate-300 px-2.5 py-1.5 text-right text-sm text-slate-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                        <span className="text-xs text-slate-400">FCFA</span>
                                    </span>
                                )}
                                {checked && !mayTypePrice && (
                                    <span className="shrink-0 text-sm font-semibold text-slate-900">{formatCurrency(parseFloat(selection.price) || 0)}</span>
                                )}
                            </label>

                            {checked && (
                                <PriceChoices
                                    service={service}
                                    price={selection.price}
                                    currentPrice={initialPrices[service.uuid]}
                                    mayTypePrice={mayTypePrice}
                                    onPick={(price) => setPrice((v) => v.service_uuid === service.uuid && v.service_variant_uuid === null, price)}
                                />
                            )}

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
