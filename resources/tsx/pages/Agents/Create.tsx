import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { Zone } from '@/types';

interface AgentsCreateProps {
    zones: Zone[];
}

export default function AgentsCreate({ zones }: AgentsCreateProps) {
    return (
        <AppLayout title="Add Agent" breadcrumbs={[{ label: 'Agents', href: '/agents' }, { label: 'Add Agent' }]}>
            <Head title="Add Agent" />

            <div className="mb-6 animate-fade-up">
                <h1 className="font-display text-2xl font-semibold tracking-tight text-slate-900">Add Agent</h1>
                <p className="mt-1 text-sm text-slate-500">Register a new field agent and assign them to a zone.</p>
            </div>

            <Card className="max-w-2xl animate-fade-up" style={{ animationDelay: '0.08s' }}>
                <CardHeader>
                    <h2 className="font-display text-base font-semibold text-slate-900">Agent details</h2>
                    <p className="mt-0.5 text-xs text-slate-500">Fields marked required must be filled in.</p>
                </CardHeader>
                <CardBody>
                    <Form action="/agents" method="post" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput id="name" name="name" label="Name" required error={errors.name} />
                                <TextInput id="location" name="location" label="Location" required error={errors.location} />
                                <TextInput id="phone" name="phone" label="Phone" required error={errors.phone} />

                                <SelectInput id="zone_uuid" name="zone_uuid" label="Zone" required error={errors.zone_uuid} defaultValue="">
                                    <option value="" disabled>
                                        Select a zone
                                    </option>
                                    {zones.map((zone) => (
                                        <option key={zone.uuid} value={zone.uuid}>
                                            {zone.name}
                                        </option>
                                    ))}
                                </SelectInput>

                                <TextInput
                                    id="salary"
                                    name="salary"
                                    label="Salary (FCFA)"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    required
                                    error={errors.salary}
                                />
                                <TextInput id="email" name="email" label="Email" type="email" error={errors.email} />
                                <TextInput id="dob" name="dob" label="Date of Birth" type="date" error={errors.dob} />

                                <SelectInput
                                    id="marital_status"
                                    name="marital_status"
                                    label="Married"
                                    error={errors.marital_status}
                                    defaultValue=""
                                >
                                    <option value="">Unspecified</option>
                                    <option value="yes">Yes</option>
                                    <option value="no">No</option>
                                </SelectInput>

                                <TextInput
                                    id="children"
                                    name="children"
                                    label="Number of Children"
                                    type="number"
                                    min={0}
                                    error={errors.children}
                                />

                                <SelectInput id="status" name="status" label="Status" error={errors.status} defaultValue="active">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </SelectInput>

                                <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                                    <Link href="/agents" className="w-full sm:w-auto">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto"
                                        >
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto"
                                    >
                                        {processing ? (
                                            <>
                                                <LoadingSpinner />
                                                Saving…
                                            </>
                                        ) : (
                                            'Create Agent'
                                        )}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </CardBody>
            </Card>
        </AppLayout>
    );
}
