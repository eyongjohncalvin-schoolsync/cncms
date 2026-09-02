import { Form, Head, Link } from '@inertiajs/react';
import { IconBuildingSkyscraper } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';

export default function LandlordTenantsCreate() {
    return (
        <LandlordLayout
            title="Add Tenant"
            breadcrumbs={[{ label: 'Landlord' }, { label: 'Tenants', href: '/landlord/tenants' }, { label: 'Add Tenant' }]}
        >
            <Head title="Landlord — Add Tenant" />

            <div className="animate-fade-up mb-4 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconBuildingSkyscraper size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">Add Tenant</h1>
                    <p className="text-sm text-slate-500">Provision a brand-new workspace for an LCO client.</p>
                </div>
            </div>

            <Card className="animate-fade-up max-w-md" style={{ animationDelay: '80ms' }}>
                <CardHeader>
                    <h2 className="text-sm font-semibold text-slate-900">New workspace</h2>
                </CardHeader>
                <CardBody>
                    <p className="mb-4 text-sm text-slate-600">
                        Creating a tenant provisions a brand-new database schema and runs all tenant
                        migrations and seeders. This may take a few seconds — please don&apos;t navigate
                        away while it&apos;s running.
                    </p>

                    <Form action="/landlord/tenants" method="post" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput
                                    id="name"
                                    name="name"
                                    label="Name"
                                    error={errors.name}
                                    required
                                    className="rounded-lg px-3.5 py-2.5"
                                />
                                <TextInput
                                    id="slug"
                                    name="slug"
                                    label="Slug"
                                    placeholder="e.g. buea-operator"
                                    error={errors.slug}
                                    required
                                    className="rounded-lg px-3.5 py-2.5"
                                />

                                <div className="flex flex-col-reverse items-center gap-3 border-t border-slate-100 pt-4 sm:flex-row">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto"
                                    >
                                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                        {processing ? 'Provisioning…' : 'Create Tenant'}
                                    </Button>
                                    <Link href="/landlord/tenants" className="text-sm text-slate-600 hover:underline">
                                        Cancel
                                    </Link>
                                </div>
                            </>
                        )}
                    </Form>
                </CardBody>
            </Card>
        </LandlordLayout>
    );
}
