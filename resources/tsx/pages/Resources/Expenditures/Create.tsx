import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { ExpenseCategory } from '@/types';

interface ExpendituresCreateProps {
    categories: ExpenseCategory[];
}

export default function ExpendituresCreate({ categories }: ExpendituresCreateProps) {
    const today = new Date().toISOString().slice(0, 10);

    return (
        <AppLayout
            title="Record Expense"
            breadcrumbs={[
                { label: 'Resources', href: '/resources' },
                { label: 'Expenditures', href: '/resources/expenditures' },
                { label: 'Record Expenditure' },
            ]}
        >
            <Head title="Record Expense" />

            <div className="mb-6 animate-fade-up">
                <h1 className="font-display text-2xl text-slate-900">Record Expense</h1>
                <p className="mt-1 text-sm text-slate-500">Log a new expenditure against a category for this period.</p>
            </div>

            <Card className="max-w-xl animate-fade-up" style={{ animationDelay: '80ms' }}>
                <CardHeader>
                    <h2 className="text-sm font-semibold text-slate-900">Expense details</h2>
                    <p className="mt-0.5 text-xs text-slate-500">Fields marked required must be filled in.</p>
                </CardHeader>
                <CardBody>
                    <Form action="/resources/expenditures" method="post" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <SelectInput id="category_uuid" name="category_uuid" label="Category" error={errors.category_uuid} defaultValue="">
                                    <option value="">Select a category</option>
                                    {categories.map((category) => (
                                        <option key={category.uuid} value={category.uuid}>
                                            {category.name}
                                        </option>
                                    ))}
                                </SelectInput>

                                <TextInput
                                    id="amount"
                                    name="amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    label="Amount (FCFA)"
                                    error={errors.amount}
                                    required
                                />

                                <TextInput
                                    id="spent_at"
                                    name="spent_at"
                                    type="date"
                                    label="Date"
                                    defaultValue={today}
                                    error={errors.spent_at}
                                    required
                                />

                                <TextInput
                                    id="description"
                                    name="description"
                                    label="Description"
                                    error={errors.description}
                                    placeholder="e.g. Fuel for zone rounds"
                                />

                                <div className="flex flex-col gap-1">
                                    <label htmlFor="notes" className="text-sm font-medium text-slate-700">
                                        Notes (optional)
                                    </label>
                                    <textarea
                                        id="notes"
                                        name="notes"
                                        rows={3}
                                        className={`rounded-md border-0 px-3 py-2 text-base text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm ${
                                            errors.notes ? 'ring-red-400 focus:ring-red-500' : ''
                                        }`}
                                    />
                                    {errors.notes && <p className="text-xs text-red-600">{errors.notes}</p>}
                                </div>

                                <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                                    <Link href="/resources/expenditures" className="w-full sm:w-auto">
                                        <Button type="button" variant="secondary" className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing} className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                        {processing && <LoadingSpinner className="text-white" />}
                                        {processing ? 'Saving…' : 'Record Expense'}
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
