import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { Branch, Zone } from '@/types';

interface ZonesEditProps {
    zone: Zone;
    branches: Branch[];
}

export default function ZonesEdit({ zone, branches }: ZonesEditProps) {
    return (
        <AppLayout
            title={`Edit ${zone.name}`}
            breadcrumbs={[
                { label: 'Zones', href: '/zones' },
                { label: zone.name, href: `/zones/${zone.uuid}/edit` },
                { label: 'Edit' },
            ]}
        >
            <Head title={`Edit ${zone.name}`} />

            <div className="animate-fade-up mb-6">
                <h2 className="font-display text-2xl text-slate-900">Edit {zone.name}</h2>
                <p className="mt-1 text-sm text-slate-500">Update this zone's name and town.</p>
            </div>

            <Card className="animate-fade-up max-w-md" style={{ animationDelay: '0.05s' }}>
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">Zone details</h3>
                </CardHeader>
                <CardBody>
                    <Form action={`/zones/${zone.uuid}`} method="patch" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput
                                    id="name"
                                    name="name"
                                    label="Name"
                                    defaultValue={zone.name}
                                    error={errors.name}
                                    required
                                />
                                <TextInput id="town" name="town" label="Town" defaultValue={zone.town} error={errors.town} />

                                {branches.length > 1 && (
                                    <SelectInput
                                        id="branch_uuid"
                                        name="branch_uuid"
                                        label="Branch"
                                        defaultValue={zone.branch_uuid ?? ''}
                                        error={errors.branch_uuid}
                                        required
                                    >
                                        <option value="">Select a branch</option>
                                        {branches.map((branch) => (
                                            <option key={branch.uuid} value={branch.uuid}>
                                                {branch.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                )}

                                <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center">
                                    <Button type="submit" disabled={processing} className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                        {processing && <LoadingSpinner className="text-white" />}
                                        {processing ? 'Saving…' : 'Save Changes'}
                                    </Button>
                                    <Link href="/zones" className="w-full sm:w-auto">
                                        <Button type="button" variant="secondary" className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                            Cancel
                                        </Button>
                                    </Link>
                                </div>
                            </>
                        )}
                    </Form>
                </CardBody>
            </Card>
        </AppLayout>
    );
}
