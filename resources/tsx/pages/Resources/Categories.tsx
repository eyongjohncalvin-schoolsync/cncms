import { Form, Head, router, usePage } from '@inertiajs/react';
import { ComponentType, useEffect, useState } from 'react';
import {
    IconAntenna,
    IconBolt,
    IconBuilding,
    IconCategoryPlus,
    IconCreditCard,
    IconDeviceTv,
    IconDots,
    IconFolderQuestion,
    IconTool,
    IconTruck,
    IconUsers,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { hasPermission } from '@/lib/permissions';
import type { ExpenseCategory, PageProps } from '@/types';

interface CategoriesProps {
    categories: ExpenseCategory[];
}

// Maps the "ti-*" icon names stored per-category (seeded in
// TenantDatabaseSeeder) to their @tabler/icons-react equivalents. Any icon
// name that doesn't map cleanly (or a null icon) falls back to a generic
// folder icon rather than skipping the visual entirely.
const CATEGORY_ICON_MAP: Record<string, ComponentType<{ size?: number; stroke?: number }>> = {
    'ti-users': IconUsers,
    'ti-truck': IconTruck,
    'ti-tool': IconTool,
    'ti-building': IconBuilding,
    'ti-bolt': IconBolt,
    'ti-credit-card': IconCreditCard,
    'ti-antenna': IconAntenna,
    'ti-device-tv': IconDeviceTv,
    'ti-dots': IconDots,
};

function categoryIcon(icon: string | null) {
    return (icon && CATEGORY_ICON_MAP[icon]) || IconFolderQuestion;
}

// Cycles the same tone palette StatCard uses so each category chip picks up
// a distinct accent instead of every card looking identical. Uses the same
// strengthened "-100"/"-700" pairing as StatCard's icon chips and Badge.tsx.
const CHIP_TONES = ['bg-blue-100 text-blue-700', 'bg-green-100 text-green-700', 'bg-purple-100 text-purple-700', 'bg-amber-100 text-amber-700', 'bg-pink-100 text-pink-700', 'bg-cyan-100 text-cyan-700'];

export default function Categories({ categories }: CategoriesProps) {
    const { auth } = usePage<PageProps>().props;
    // RBAC v2 Wave 4: ExpenseCategoryPolicy::create/update/delete →
    // `expense_categories.manage`.
    const canManage = hasPermission(auth.user?.permissions, 'expense_categories.manage');

    const [showForm, setShowForm] = useState(false);
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', () => setIsLoading(true));
        const removeFinish = router.on('finish', () => setIsLoading(false));

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    function deactivate(category: ExpenseCategory) {
        if (!window.confirm(`Deactivate the "${category.name}" category?`)) {
            return;
        }

        router.delete(`/resources/categories/${category.uuid}`, { preserveScroll: true });
    }

    return (
        <AppLayout
            title="Expense Categories"
            breadcrumbs={[{ label: 'Resources', href: '/resources' }, { label: 'Categories' }]}
        >
            <Head title="Expense Categories" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4 animate-fade-up">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="font-display text-2xl text-slate-900">Expense Categories</h1>
                        {isLoading && <LoadingSpinner className="text-blue-600" />}
                    </div>
                    <p className="mt-1 text-sm text-slate-500">Organize expenditures into categories for reporting and budgets.</p>
                </div>
                {canManage && (
                    <Button
                        variant={showForm ? 'secondary' : 'primary'}
                        onClick={() => setShowForm((current) => !current)}
                        className="rounded-lg px-4 py-2.5 text-sm font-semibold"
                    >
                        <IconCategoryPlus size={18} stroke={1.75} />
                        {showForm ? 'Close' : 'Add Category'}
                    </Button>
                )}
            </div>

            {canManage && showForm && (
                <Card className="mb-4 max-w-lg animate-fade-up">
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">New category</h2>
                    </CardHeader>
                    <CardBody>
                        <Form
                            action="/resources/categories"
                            method="post"
                            className="flex flex-col gap-4"
                            onSuccess={() => setShowForm(false)}
                        >
                            {({ errors, processing }) => (
                                <>
                                    <TextInput id="name" name="name" label="Name" error={errors.name} required />
                                    <TextInput
                                        id="icon"
                                        name="icon"
                                        label="Icon (Tabler class, optional)"
                                        placeholder="ti-dots"
                                        error={errors.icon}
                                    />
                                    <TextInput
                                        id="sort_order"
                                        name="sort_order"
                                        type="number"
                                        label="Sort order (optional)"
                                        error={errors.sort_order}
                                    />
                                    <div>
                                        <Button type="submit" disabled={processing}>
                                            {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                            {processing ? 'Saving…' : 'Create Category'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardBody>
                </Card>
            )}

            {categories.length === 0 ? (
                <EmptyState title="No categories yet" description="Add a category to start recording expenditures." />
            ) : (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {categories.map((category, index) => {
                        const Icon = categoryIcon(category.icon);
                        const chipTone = CHIP_TONES[index % CHIP_TONES.length];

                        return (
                            <Card
                                key={category.uuid}
                                className="animate-fade-up p-4 transition-shadow hover:shadow-md"
                                style={{ animationDelay: `${Math.min(index, 12) * 40}ms` }}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex items-start gap-3">
                                        <div className={`rounded-lg p-2 ${chipTone}`}>
                                            <Icon size={20} stroke={1.75} />
                                        </div>
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">{category.name}</p>
                                            <p className="mt-0.5 text-xs text-slate-500">Sort order: {category.sort_order}</p>
                                        </div>
                                    </div>
                                    <Badge tone={category.is_active ? 'green' : 'slate'}>
                                        {category.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </div>
                                {canManage && category.is_active && (
                                    <div className="mt-4 border-t border-slate-100 pt-3">
                                        <button
                                            type="button"
                                            onClick={() => deactivate(category)}
                                            className="text-sm font-medium text-red-600 hover:text-red-700"
                                        >
                                            Deactivate
                                        </button>
                                    </div>
                                )}
                            </Card>
                        );
                    })}
                </div>
            )}
        </AppLayout>
    );
}
