import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import type { Customer, CustomerLevel, CustomerStatus, Zone } from '@/types';

interface CustomersEditProps {
    customer: Customer;
    zones: Zone[];
}

const levelOptions: CustomerLevel[] = ['normal', 'Vip', 'Operator'];
const statusOptions: CustomerStatus[] = ['active', 'passive', 'disconnected', 'suspended'];

export default function CustomersEdit({ customer, zones }: CustomersEditProps) {
    return (
        <AppLayout title={`Edit ${customer.name}`}>
            <Head title={`Edit ${customer.name}`} />

            <Card className="max-w-xl">
                <CardBody>
                    <Form action={`/customers/${customer.uuid}`} method="patch" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput
                                    id="name"
                                    name="name"
                                    label="Name"
                                    defaultValue={customer.name}
                                    error={errors.name}
                                    required
                                />
                                <SelectInput
                                    id="zone_uuid"
                                    name="zone_uuid"
                                    label="Zone"
                                    defaultValue={customer.zone_uuid}
                                    error={errors.zone_uuid}
                                >
                                    {zones.map((zone) => (
                                        <option key={zone.uuid} value={zone.uuid}>
                                            {zone.name}
                                        </option>
                                    ))}
                                </SelectInput>
                                <TextInput
                                    id="phone"
                                    name="phone"
                                    label="Phone"
                                    defaultValue={customer.phone ?? ''}
                                    error={errors.phone}
                                />
                                <TextInput
                                    id="location"
                                    name="location"
                                    label="Location"
                                    defaultValue={customer.location ?? ''}
                                    error={errors.location}
                                />
                                <TextInput
                                    id="bill"
                                    name="bill"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    label="Monthly Bill (FCFA)"
                                    defaultValue={customer.bill}
                                    error={errors.bill}
                                    required
                                />
                                <SelectInput id="level" name="level" label="Level" defaultValue={customer.level} error={errors.level}>
                                    {levelOptions.map((level) => (
                                        <option key={level} value={level}>
                                            {level}
                                        </option>
                                    ))}
                                </SelectInput>
                                <SelectInput
                                    id="status"
                                    name="status"
                                    label="Status"
                                    defaultValue={customer.status}
                                    error={errors.status}
                                >
                                    {statusOptions.map((status) => (
                                        <option key={status} value={status}>
                                            {status}
                                        </option>
                                    ))}
                                </SelectInput>

                                <div className="flex items-center gap-3 pt-2">
                                    <Button type="submit" disabled={processing}>
                                        Save Changes
                                    </Button>
                                    <Link href={`/customers/${customer.uuid}`} className="text-sm text-slate-600 hover:underline">
                                        Cancel
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
