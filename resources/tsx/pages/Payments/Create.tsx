import { FormEvent, useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Customer, PaymentFrequency } from '@/types';

interface PaymentsCreateProps {
    customers: Customer[];
}

export default function PaymentsCreate({ customers }: PaymentsCreateProps) {
    const [search, setSearch] = useState('');

    const { data, setData, post, processing, errors } = useForm({
        customer_uuid: '',
        amount: '',
        credit: '',
        frequency: 'monthly' as PaymentFrequency,
        months: '',
    });

    const filteredCustomers = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (!term) {
            return customers;
        }

        return customers.filter(
            (customer) => customer.name.toLowerCase().includes(term) || (customer.phone ?? '').toLowerCase().includes(term),
        );
    }, [customers, search]);

    const selectedCustomer = customers.find((customer) => customer.uuid === data.customer_uuid) ?? null;

    const amountBelowBill =
        selectedCustomer && data.amount !== '' && Number(data.amount) < Number(selectedCustomer.bill) && data.frequency === 'monthly';

    function selectCustomer(uuid: string) {
        setData((current) => {
            const customer = customers.find((c) => c.uuid === uuid);

            return {
                ...current,
                customer_uuid: uuid,
                amount: customer && current.frequency === 'monthly' ? customer.bill : current.amount,
            };
        });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/payments');
    }

    return (
        <AppLayout title="Record Payment">
            <Head title="Record Payment" />

            <form onSubmit={submit} className="max-w-2xl">
                <Card>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Record a payment</h2>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <label className="text-sm font-medium text-slate-700">Customer</label>
                            <TextInput
                                placeholder="Search by name or phone…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <SelectInput
                                value={data.customer_uuid}
                                onChange={(e) => selectCustomer(e.target.value)}
                                error={errors.customer_uuid}
                            >
                                <option value="">Select a customer…</option>
                                {filteredCustomers.map((customer) => (
                                    <option key={customer.uuid} value={customer.uuid}>
                                        {customer.name}
                                        {customer.phone ? ` — ${customer.phone}` : ''} ({customer.zone_name})
                                    </option>
                                ))}
                            </SelectInput>
                            {selectedCustomer && (
                                <p className="text-xs text-slate-500">
                                    Monthly bill: {formatCurrency(selectedCustomer.bill)} · Status: {selectedCustomer.status}
                                </p>
                            )}
                        </div>

                        <TextInput
                            id="amount"
                            label="Amount (FCFA)"
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                            error={errors.amount}
                        />
                        {amountBelowBill && (
                            <p className="-mt-3 text-xs text-yellow-600">
                                Amount is less than this customer&apos;s monthly bill ({formatCurrency(selectedCustomer!.bill)}).
                            </p>
                        )}

                        <div className="flex flex-col gap-1">
                            <label className="text-sm font-medium text-slate-700">Frequency</label>
                            <div className="flex gap-4">
                                {(['monthly', 'months', 'yearly'] as PaymentFrequency[]).map((freq) => (
                                    <label key={freq} className="flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="radio"
                                            name="frequency"
                                            value={freq}
                                            checked={data.frequency === freq}
                                            onChange={() => setData('frequency', freq)}
                                        />
                                        {freq === 'monthly' ? 'Monthly' : freq === 'months' ? 'Multi-month' : 'Yearly'}
                                    </label>
                                ))}
                            </div>
                            {errors.frequency && <p className="text-xs text-red-600">{errors.frequency}</p>}
                        </div>

                        {data.frequency === 'months' && (
                            <TextInput
                                id="months"
                                label="Number of months"
                                type="number"
                                min="1"
                                value={data.months}
                                onChange={(e) => setData('months', e.target.value)}
                                error={errors.months}
                            />
                        )}

                        <TextInput
                            id="credit"
                            label="Credit (optional)"
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.credit}
                            onChange={(e) => setData('credit', e.target.value)}
                            error={errors.credit}
                        />

                        <div className="flex justify-end gap-2">
                            <Link href="/payments">
                                <Button type="button" variant="secondary">
                                    Cancel
                                </Button>
                            </Link>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving…' : 'Record Payment'}
                            </Button>
                        </div>
                    </CardBody>
                </Card>
            </form>
        </AppLayout>
    );
}
