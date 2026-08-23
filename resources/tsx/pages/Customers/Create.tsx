import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import type { CustomerLevel, Zone } from '@/types';

interface CustomersCreateProps {
    zones: Zone[];
}

const levelOptions: CustomerLevel[] = ['normal', 'Vip', 'Operator'];

export default function CustomersCreate({ zones }: CustomersCreateProps) {
    return (
        <AppLayout title="Add Customer">
            <Head title="Add Customer" />

            <Card className="max-w-xl">
                <CardBody>
                    <Form action="/customers" method="post" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput id="name" name="name" label="Name" error={errors.name} required />
                                <SelectInput id="zone_uuid" name="zone_uuid" label="Zone" error={errors.zone_uuid} defaultValue="">
                                    <option value="">Select a zone</option>
                                    {zones.map((zone) => (
                                        <option key={zone.uuid} value={zone.uuid}>
                                            {zone.name}
                                        </option>
                                    ))}
                                </SelectInput>
                                <TextInput id="phone" name="phone" label="Phone" error={errors.phone} />
                                <TextInput id="location" name="location" label="Location" error={errors.location} />
                                <TextInput
                                    id="bill"
                                    name="bill"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    label="Monthly Bill (FCFA)"
                                    error={errors.bill}
                                    required
                                />
                                <SelectInput id="level" name="level" label="Level" error={errors.level} defaultValue="normal">
                                    {levelOptions.map((level) => (
                                        <option key={level} value={level}>
                                            {level}
                                        </option>
                                    ))}
                                </SelectInput>
                                <TextInput id="description" name="description" label="Description" error={errors.description} />

                                <div className="flex items-center gap-3 pt-2">
                                    <Button type="submit" disabled={processing}>
                                        Create Customer
                                    </Button>
                                    <Link href="/customers" className="text-sm text-slate-600 hover:underline">
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
