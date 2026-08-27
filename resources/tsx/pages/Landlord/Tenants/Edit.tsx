import { Form, Head, Link } from '@inertiajs/react';
import { IconBrandWhatsapp, IconBuildingSkyscraper } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { LandlordTenant } from '@/types';

export default function LandlordTenantsEdit({ tenant }: { tenant: LandlordTenant }) {
    return (
        <LandlordLayout
            title={`Edit ${tenant.name}`}
            breadcrumbs={[{ label: 'Landlord' }, { label: 'Tenants', href: '/landlord/tenants' }, { label: 'Edit' }]}
        >
            <Head title={`Landlord — Edit ${tenant.name}`} />

            <div className="animate-fade-up mb-4 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconBuildingSkyscraper size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">Edit Tenant</h1>
                    <p className="text-sm text-slate-500">Manage workspace access for this client.</p>
                </div>
            </div>

            <Card className="animate-fade-up max-w-md" style={{ animationDelay: '80ms' }}>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="font-medium text-slate-900">{tenant.name}</p>
                            <p className="text-sm text-slate-500">{tenant.slug}</p>
                        </div>
                        <Badge tone={tenant.is_active ? 'green' : 'slate'}>
                            {tenant.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </div>
                </CardHeader>
                <CardBody>
                    <Form action={`/landlord/tenants/${tenant.id}`} method="patch" className="flex flex-col gap-4">
                        {({ processing }) => (
                            <>
                                <input type="hidden" name="is_active" value={tenant.is_active ? '0' : '1'} />

                                <p className="text-sm text-slate-600">
                                    {tenant.is_active
                                        ? 'Deactivating this tenant immediately blocks their staff from signing in. Their data is kept intact.'
                                        : 'Activating this tenant restores sign-in access for their staff.'}
                                </p>

                                <div className="flex items-center gap-3 border-t border-slate-100 pt-4">
                                    <Button
                                        type="submit"
                                        variant={tenant.is_active ? 'danger' : 'primary'}
                                        disabled={processing}
                                        className="rounded-lg px-5 py-2.5 text-sm font-semibold"
                                    >
                                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                        {processing
                                            ? 'Saving…'
                                            : tenant.is_active
                                              ? 'Deactivate Tenant'
                                              : 'Activate Tenant'}
                                    </Button>
                                    <Link href="/landlord/tenants" className="text-sm text-slate-600 hover:underline">
                                        Back
                                    </Link>
                                </div>
                            </>
                        )}
                    </Form>
                </CardBody>
            </Card>

            <Card className="animate-fade-up mt-6 max-w-md" style={{ animationDelay: '140ms' }}>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <IconBrandWhatsapp size={18} stroke={1.75} className="text-emerald-600" />
                            <p className="font-medium text-slate-900">Bulk WhatsApp (Twilio)</p>
                        </div>
                        <Badge tone={tenant.bulk_whatsapp_enabled ? 'green' : 'slate'}>
                            {tenant.bulk_whatsapp_enabled ? 'Enabled' : 'Disabled'}
                        </Badge>
                    </div>
                </CardHeader>
                <CardBody>
                    <Form action={`/landlord/tenants/${tenant.id}`} method="patch" className="flex flex-col gap-4">
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="bulk_whatsapp_enabled"
                                    value={tenant.bulk_whatsapp_enabled ? '0' : '1'}
                                />

                                <p className="text-sm text-slate-600">
                                    {tenant.bulk_whatsapp_enabled
                                        ? "This tenant may configure their own Twilio credentials and send bulk WhatsApp bill reminders. They pay Twilio's per-message cost directly."
                                        : 'This tenant only has the free, manual WhatsApp mode (staff send bill reminders individually via wa.me links). Enabling this entitlement unlocks the paid, bulk Twilio path in their Settings.'}
                                </p>

                                <div className="flex items-center gap-3 border-t border-slate-100 pt-4">
                                    <Button
                                        type="submit"
                                        variant={tenant.bulk_whatsapp_enabled ? 'danger' : 'primary'}
                                        disabled={processing}
                                        className="rounded-lg px-5 py-2.5 text-sm font-semibold"
                                    >
                                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                        {processing
                                            ? 'Saving…'
                                            : tenant.bulk_whatsapp_enabled
                                              ? 'Disable Bulk WhatsApp'
                                              : 'Enable Bulk WhatsApp'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </CardBody>
            </Card>
        </LandlordLayout>
    );
}
