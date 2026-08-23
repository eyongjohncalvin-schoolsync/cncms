import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Button } from '@/components/ui/Button';
import type { Zone } from '@/types';

interface AgentsCreateProps {
    zones: Zone[];
}

export default function AgentsCreate({ zones }: AgentsCreateProps) {
    return (
        <AppLayout title="Add Agent">
            <Head title="Add Agent" />

            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold text-slate-900">Add Agent</h1>
                <Link href="/agents" className="text-sm text-slate-600 hover:underline">
                    Back to Agents
                </Link>
            </div>

            <Card className="max-w-2xl">
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

                                <div className="mt-2 flex justify-end gap-2">
                                    <Link href="/agents">
                                        <Button type="button" variant="secondary">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Saving…' : 'Create Agent'}
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
