import { FormEvent, ReactNode, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { IconBroadcast, IconPlus, IconEdit, IconTrash, IconRefresh, IconStar } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';

interface ServiceVariantRow {
    uuid: string;
    name: string;
    price: string;
    active: boolean;
    subscriber_count: number;
}

interface ServiceRow {
    uuid: string;
    name: string;
    description: string | null;
    price: string;
    is_default: boolean;
    active: boolean;
    subscriber_count: number;
    variants: ServiceVariantRow[];
}

interface SettingsServicesProps {
    services: ServiceRow[];
}

/**
 * Settings -> Services — the company's service catalogue (services.md
 * sections 6-7). Every service the customer add/edit form's tick-list
 * offers is managed here: name, price, whether it's the default (pre-
 * ticked) service, active/inactive, and each service's "options" (variants
 * — section 4, e.g. a specific TV channel broadcast at its own price).
 */
export default function SettingsServices({ services }: SettingsServicesProps) {
    const [serviceModal, setServiceModal] = useState<{ mode: 'create' } | { mode: 'edit'; service: ServiceRow } | null>(null);
    const [variantModal, setVariantModal] = useState<
        { service: ServiceRow; mode: 'create' } | { service: ServiceRow; mode: 'edit'; variant: ServiceVariantRow } | null
    >(null);

    return (
        <AppLayout title="Services" breadcrumbs={[{ label: 'Settings', href: '/settings/company' }, { label: 'Services' }]}>
            <Head title="Settings — Services" />

            <SettingsTabs active="services" />

            <div className="mb-8">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/25">
                            <IconBroadcast size={24} stroke={1.75} />
                        </span>
                        <div>
                            <h1 className="font-display text-3xl font-semibold tracking-tight text-slate-900">Services</h1>
                            <p className="mt-1 text-sm text-slate-500">
                                What you offer customers — TV, Internet, and anything else, each at its own price. The one marked
                                Default is pre-ticked on every new customer.
                            </p>
                        </div>
                    </div>
                    <Button onClick={() => setServiceModal({ mode: 'create' })} className="shrink-0">
                        <IconPlus size={16} stroke={1.9} />
                        Add Service
                    </Button>
                </div>
            </div>

            <div className="space-y-4">
                {services.map((service) => (
                    <Card key={service.uuid}>
                        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex min-w-0 items-center gap-2.5">
                                <span className="font-medium text-slate-900">{service.name}</span>
                                {service.is_default && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                        <IconStar size={11} stroke={2} />
                                        Default
                                    </span>
                                )}
                                {!service.active && (
                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">Inactive</span>
                                )}
                                <span className="text-xs text-slate-400">
                                    {service.subscriber_count} subscriber{service.subscriber_count === 1 ? '' : 's'}
                                </span>
                            </div>
                            <div className="flex shrink-0 items-center gap-1.5">
                                <span className="mr-2 text-sm font-semibold text-slate-700 tabular-nums">{formatCurrency(service.price)}</span>
                                <ApplyPriceButton kind="service" uuid={service.uuid} subscriberCount={service.subscriber_count} />
                                <IconButton label="Edit service" onClick={() => setServiceModal({ mode: 'edit', service })}>
                                    <IconEdit size={15} stroke={1.75} />
                                </IconButton>
                                <IconButton
                                    label="Delete service"
                                    variant="danger"
                                    onClick={() => {
                                        if (service.subscriber_count > 0) {
                                            alert(`${service.subscriber_count} customer(s) subscribe to "${service.name}" — deactivate it instead.`);

                                            return;
                                        }

                                        if (confirm(`Delete "${service.name}"? This can't be undone.`)) {
                                            router.delete(`/settings/services/${service.uuid}`);
                                        }
                                    }}
                                >
                                    <IconTrash size={15} stroke={1.75} />
                                </IconButton>
                            </div>
                        </CardHeader>
                        <CardBody>
                            {service.description && <p className="mb-3 text-sm text-slate-500">{service.description}</p>}

                            <div className="space-y-1.5">
                                {service.variants.map((variant) => (
                                    <div
                                        key={variant.uuid}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2"
                                    >
                                        <div className="flex min-w-0 items-center gap-2">
                                            <span className="text-sm text-slate-700">{variant.name}</span>
                                            {!variant.active && (
                                                <span className="rounded-full bg-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">
                                                    inactive
                                                </span>
                                            )}
                                            <span className="text-xs text-slate-400">
                                                {variant.subscriber_count} subscriber{variant.subscriber_count === 1 ? '' : 's'}
                                            </span>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1.5">
                                            <span className="mr-1 text-sm font-medium text-slate-600 tabular-nums">{formatCurrency(variant.price)}</span>
                                            <ApplyPriceButton
                                                kind="variant"
                                                uuid={variant.uuid}
                                                serviceUuid={service.uuid}
                                                subscriberCount={variant.subscriber_count}
                                            />
                                            <IconButton label="Edit option" onClick={() => setVariantModal({ service, mode: 'edit', variant })}>
                                                <IconEdit size={13} stroke={1.75} />
                                            </IconButton>
                                            <IconButton
                                                label="Delete option"
                                                variant="danger"
                                                onClick={() => {
                                                    if (variant.subscriber_count > 0) {
                                                        alert(`${variant.subscriber_count} customer(s) hold "${variant.name}" — deactivate it instead.`);

                                                        return;
                                                    }

                                                    if (confirm(`Delete "${variant.name}"? This can't be undone.`)) {
                                                        router.delete(`/settings/services/${service.uuid}/variants/${variant.uuid}`);
                                                    }
                                                }}
                                            >
                                                <IconTrash size={13} stroke={1.75} />
                                            </IconButton>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <button
                                type="button"
                                onClick={() => setVariantModal({ service, mode: 'create' })}
                                className="mt-3 inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700"
                            >
                                <IconPlus size={13} stroke={2} />
                                Add option
                            </button>
                        </CardBody>
                    </Card>
                ))}
            </div>

            {serviceModal && (
                <ServiceFormModal
                    initial={serviceModal.mode === 'edit' ? serviceModal.service : null}
                    onClose={() => setServiceModal(null)}
                />
            )}

            {variantModal && (
                <VariantFormModal
                    service={variantModal.service}
                    initial={variantModal.mode === 'edit' ? variantModal.variant : null}
                    onClose={() => setVariantModal(null)}
                />
            )}
        </AppLayout>
    );
}

function IconButton({
    children,
    label,
    onClick,
    variant = 'default',
}: {
    children: ReactNode;
    label: string;
    onClick: () => void;
    variant?: 'default' | 'danger';
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            className={`rounded-lg p-1.5 transition-colors ${
                variant === 'danger' ? 'text-slate-400 hover:bg-red-50 hover:text-red-600' : 'text-slate-400 hover:bg-slate-100 hover:text-slate-700'
            }`}
        >
            {children}
        </button>
    );
}

/** POSTs the "apply this catalogue price to every current subscriber" action, with a confirm naming how many that affects. */
function ApplyPriceButton({
    kind,
    uuid,
    serviceUuid,
    subscriberCount,
}: {
    kind: 'service' | 'variant';
    uuid: string;
    serviceUuid?: string;
    subscriberCount: number;
}) {
    if (subscriberCount === 0) {
        return null;
    }

    const url = kind === 'service' ? `/settings/services/${uuid}/apply-price` : `/settings/services/${serviceUuid}/variants/${uuid}/apply-price`;

    return (
        <IconButton
            label={`Apply this price to all ${subscriberCount} current subscriber(s)`}
            onClick={() => {
                if (confirm(`Set this price for all ${subscriberCount} current subscriber(s)? Their bill will be recalculated.`)) {
                    router.post(url, {}, { preserveScroll: true });
                }
            }}
        >
            <IconRefresh size={kind === 'service' ? 15 : 13} stroke={1.75} />
        </IconButton>
    );
}

function ServiceFormModal({ initial, onClose }: { initial: ServiceRow | null; onClose: () => void }) {
    const { data, setData, post, patch, processing, errors } = useForm({
        name: initial?.name ?? '',
        description: initial?.description ?? '',
        price: initial?.price ?? '0',
        is_default: initial?.is_default ?? false,
        active: initial?.active ?? true,
    });

    function submit(e: FormEvent) {
        e.preventDefault();

        const options = { onSuccess: onClose, preserveScroll: true };

        if (initial) {
            patch(`/settings/services/${initial.uuid}`, options);
        } else {
            post('/settings/services', options);
        }
    }

    return (
        <Modal open onClose={onClose} title={initial ? `Edit ${initial.name}` : 'Add Service'}>
            <form onSubmit={submit} className="space-y-4">
                <TextInput id="name" label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                <TextInput
                    id="description"
                    label="Description (optional)"
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    error={errors.description}
                    placeholder="Shown as helper text on the customer form"
                />
                <TextInput
                    id="price"
                    type="number"
                    step="0.01"
                    min="0"
                    label="Price (FCFA/month)"
                    value={data.price}
                    onChange={(e) => setData('price', e.target.value)}
                    error={errors.price}
                    required
                />
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={data.is_default}
                        onChange={(e) => setData('is_default', e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    Pre-tick this service on new customers
                </label>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={data.active}
                        onChange={(e) => setData('active', e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    Active (offered on the customer form)
                </label>

                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                        {initial ? 'Save' : 'Add Service'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function VariantFormModal({ service, initial, onClose }: { service: ServiceRow; initial: ServiceVariantRow | null; onClose: () => void }) {
    const { data, setData, post, patch, processing, errors } = useForm({
        name: initial?.name ?? '',
        price: initial?.price ?? '0',
        active: initial?.active ?? true,
    });

    function submit(e: FormEvent) {
        e.preventDefault();

        const options = { onSuccess: onClose, preserveScroll: true };

        if (initial) {
            patch(`/settings/services/${service.uuid}/variants/${initial.uuid}`, options);
        } else {
            post(`/settings/services/${service.uuid}/variants`, options);
        }
    }

    return (
        <Modal open onClose={onClose} title={initial ? `Edit option — ${service.name}` : `Add option — ${service.name}`}>
            <form onSubmit={submit} className="space-y-4">
                <TextInput
                    id="variant-name"
                    label="Name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    error={errors.name}
                    placeholder="e.g., Local News Channel"
                    required
                />
                <TextInput
                    id="variant-price"
                    type="number"
                    step="0.01"
                    min="0"
                    label="Price (FCFA/month, on top of the base service)"
                    value={data.price}
                    onChange={(e) => setData('price', e.target.value)}
                    error={errors.price}
                    required
                />
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={data.active}
                        onChange={(e) => setData('active', e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    Active (offered on the customer form)
                </label>

                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                        {initial ? 'Save' : 'Add Option'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
