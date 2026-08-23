import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Button } from '@/components/ui/Button';
import type { Agent, Zone } from '@/types';

interface AgentsEditProps {
    agent: Agent;
    zones: Zone[];
}

export default function AgentsEdit({ agent, zones }: AgentsEditProps) {
    return (
        <AppLayout title="Edit Agent">
            <Head title={`Edit ${agent.name}`} />

            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold text-slate-900">Edit Agent</h1>
                <Link href="/agents" className="text-sm text-slate-600 hover:underline">
                    Back to Agents
                </Link>
            </div>

            <Card className="max-w-2xl">
                <CardBody>
                    <Form action={`/agents/${agent.uuid}`} method="put" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput id="name" name="name" label="Name" required defaultValue={agent.name} error={errors.name} />
                                <TextInput
                                    id="location"
                                    name="location"
                                    label="Location"
                                    required
                                    defaultValue={agent.location}
                                    error={errors.location}
                                />
                                <TextInput id="phone" name="phone" label="Phone" required defaultValue={agent.phone} error={errors.phone} />

                                <SelectInput
                                    id="zone_uuid"
                                    name="zone_uuid"
                                    label="Zone"
                                    required
                                    defaultValue={agent.zone_uuid}
                                    error={errors.zone_uuid}
                                >
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
                                    defaultValue={agent.salary}
                                    error={errors.salary}
                                />
                                <TextInput
                                    id="email"
                                    name="email"
                                    label="Email"
                                    type="email"
                                    defaultValue={agent.email ?? ''}
                                    error={errors.email}
                                />
                                <TextInput
                                    id="dob"
                                    name="dob"
                                    label="Date of Birth"
                                    type="date"
                                    defaultValue={agent.dob ? agent.dob.substring(0, 10) : ''}
                                    error={errors.dob}
                                />

                                <SelectInput
                                    id="marital_status"
                                    name="marital_status"
                                    label="Married"
                                    defaultValue={agent.marital_status ?? ''}
                                    error={errors.marital_status}
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
                                    defaultValue={agent.children ?? ''}
                                    error={errors.children}
                                />

                                <SelectInput id="status" name="status" label="Status" defaultValue={agent.status} error={errors.status}>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </SelectInput>

                                <p className="text-xs text-slate-500">
                                    Last synced: {agent.last_sync_at ?? 'Never'}
                                </p>

                                <div className="mt-2 flex justify-end gap-2">
                                    <Link href="/agents">
                                        <Button type="button" variant="secondary">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Saving…' : 'Save Changes'}
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
