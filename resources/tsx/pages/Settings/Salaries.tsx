import { Form, Head, router } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Table, TableBody, TableHead, Td, Th } from '@/components/ui/Table';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';

interface Grade {
    uuid: string;
    name: string;
    min_amount: string;
    max_amount: string;
    is_active: boolean;
}

interface PendingSalary {
    uuid: string;
    person: string | null;
    branch: string | null;
    grade: string | null;
    range: [string, string] | null;
    amount: string;
    reason: string | null;
    set_by: string | null;
    requested_at: string | null;
}

/**
 * Settings → Salaries (branches v2 D8, App\Http\Controllers\SalaryController):
 * headquarters' salary grades (each with a range), and the salaries set
 * outside their grade's range that wait for headquarters' approval.
 */
export default function Salaries({ grades, pending }: { grades: Grade[]; pending: PendingSalary[] }) {
    function decide(salary: PendingSalary, decision: 'approve' | 'reject') {
        const note = decision === 'reject' ? window.prompt('Why is this salary rejected? (optional)') : null;

        if (decision === 'reject' && note === null) {
            return;
        }

        router.post(`/settings/salaries/${salary.uuid}/${decision}`, { note: note ?? '' }, { preserveScroll: true });
    }

    return (
        <AppLayout title="Salaries" breadcrumbs={[{ label: 'Settings' }, { label: 'Salaries' }]}>
            <Head title="Salaries" />
            <SettingsTabs active="salaries" />

            <Card className="animate-fade-up mb-6">
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">Waiting for approval</h3>
                    <p className="mt-1 text-xs text-slate-500">
                        Salaries a branch set outside their grade's range. Until you decide, the person keeps their current salary.
                    </p>
                </CardHeader>
                {pending.length === 0 ? (
                    <CardBody>
                        <EmptyState title="Nothing waiting" description="Every salary is inside its grade's range." />
                    </CardBody>
                ) : (
                    <Table label="Salaries waiting for approval">
                        <TableHead>
                            <Th>Person</Th>
                            <Th>Branch</Th>
                            <Th>Grade (range)</Th>
                            <Th>Proposed</Th>
                            <Th>Reason</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {pending.map((salary) => (
                                <tr key={salary.uuid}>
                                    <Td className="font-medium text-slate-900">{salary.person ?? '—'}</Td>
                                    <Td>{salary.branch ?? 'Headquarters'}</Td>
                                    <Td>
                                        {salary.grade ?? 'No grade'}
                                        {salary.range && (
                                            <span className="block text-xs text-slate-500">
                                                {formatCurrency(salary.range[0])} – {formatCurrency(salary.range[1])}
                                            </span>
                                        )}
                                    </Td>
                                    <Td>{formatCurrency(salary.amount)}</Td>
                                    <Td>
                                        {salary.reason ?? '—'}
                                        {salary.set_by && <span className="block text-xs text-slate-500">by {salary.set_by}</span>}
                                    </Td>
                                    <Td>
                                        <div className="flex gap-2">
                                            <Button type="button" onClick={() => decide(salary, 'approve')} className="px-3 py-1.5 text-sm">
                                                Approve
                                            </Button>
                                            <Button type="button" variant="secondary" onClick={() => decide(salary, 'reject')} className="px-3 py-1.5 text-sm">
                                                Reject
                                            </Button>
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <Card className="animate-fade-up p-0">
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Salary grades</h3>
                        <p className="mt-1 text-xs text-slate-500">
                            Branches set each worker's salary inside a grade's range; outside it, the salary comes here for approval.
                        </p>
                    </CardHeader>
                    {grades.length === 0 ? (
                        <CardBody>
                            <EmptyState title="No grades yet" description="Until you add grades, salaries are saved without approval." />
                        </CardBody>
                    ) : (
                        <Table label="Salary grades">
                            <TableHead>
                                <Th>Grade</Th>
                                <Th>Minimum</Th>
                                <Th>Maximum</Th>
                                <Th>Active</Th>
                            </TableHead>
                            <TableBody>
                                {grades.map((grade) => (
                                    <tr key={grade.uuid}>
                                        <Td className="font-medium text-slate-900">{grade.name}</Td>
                                        <Td>{formatCurrency(grade.min_amount)}</Td>
                                        <Td>{formatCurrency(grade.max_amount)}</Td>
                                        <Td>
                                            <input
                                                type="checkbox"
                                                aria-label={`${grade.name} active`}
                                                checked={grade.is_active}
                                                onChange={(e) =>
                                                    router.patch(
                                                        `/settings/salaries/grades/${grade.uuid}`,
                                                        { name: grade.name, min_amount: grade.min_amount, max_amount: grade.max_amount, is_active: e.target.checked },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="h-4 w-4 rounded border-slate-300"
                                            />
                                        </Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Card>

                <Card className="animate-fade-up">
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Add a grade</h3>
                    </CardHeader>
                    <CardBody>
                        <Form action="/settings/salaries/grades" method="post" resetOnSuccess options={{ preserveScroll: true }} className="flex flex-col gap-4">
                            {({ errors, processing }) => (
                                <>
                                    <TextInput id="name" name="name" label="Name" placeholder="e.g. Field technician" error={errors.name} required />
                                    <TextInput id="min_amount" name="min_amount" type="number" min="0" step="1" label="Minimum (FCFA)" error={errors.min_amount} required />
                                    <TextInput id="max_amount" name="max_amount" type="number" min="0" step="1" label="Maximum (FCFA)" error={errors.max_amount} required />
                                    <Button type="submit" disabled={processing}>
                                        {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                        Add grade
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}
