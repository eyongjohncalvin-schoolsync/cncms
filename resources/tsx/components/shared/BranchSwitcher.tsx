import { router } from '@inertiajs/react';
import { IconBuildingStore } from '@tabler/icons-react';
import { useTranslation } from 'react-i18next';
import type { BranchView } from '@/types';

/**
 * Header branch switcher (branches v2, docs/plans/branches-v2.md §8) — lets
 * the owner and other unfenced staff narrow every screen to one branch, or
 * back to all branches. Persisted in the session by
 * PUT /branch-view (App\Http\Controllers\BranchViewController). Rendered
 * only when the shared `branchView` prop is set: never for a branch-fenced
 * user, never for a one-branch company.
 */
export function BranchSwitcher({ branchView }: { branchView: BranchView }) {
    const { t } = useTranslation();

    function switchTo(branchUuid: string) {
        router.put('/branch-view', { branch_uuid: branchUuid || null }, { preserveScroll: true });
    }

    return (
        <label className="flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-600">
            <IconBuildingStore size={15} stroke={1.75} aria-hidden="true" />
            <span className="sr-only">{t('common.branch_view')}</span>
            <select
                value={branchView.current ?? ''}
                onChange={(e) => switchTo(e.target.value)}
                className="max-w-[9rem] truncate border-0 bg-transparent p-0 pr-5 text-xs font-medium text-slate-700 focus:ring-0"
            >
                <option value="">{t('common.all_branches')}</option>
                {branchView.branches.map((branch) => (
                    <option key={branch.uuid} value={branch.uuid}>
                        {branch.is_headquarters ? `${branch.name} (${t('common.headquarters')})` : branch.name}
                    </option>
                ))}
            </select>
        </label>
    );
}
