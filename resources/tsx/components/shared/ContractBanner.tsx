import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { IconSignature } from '@tabler/icons-react';

/**
 * "Please sign your service contract" — the shared `contractPending` prop
 * (App\Services\ContractService::awaitingSignature), super/admin only.
 * Hidden on the contract page itself. Rendered by AppLayout.
 */
export function ContractBanner({ version }: { version: number | null | undefined }) {
    const { t } = useTranslation();
    const { url } = usePage();

    if (!version || url.startsWith('/settings/contract')) {
        return null;
    }

    return (
        <div role="status" className="flex items-center gap-2.5 border-b border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-900 md:px-6">
            <IconSignature size={18} stroke={1.75} className="shrink-0" />
            <p className="min-w-0 flex-1">{t('common.contract_pending', { version })}</p>
            <Link href="/settings/contract" className="shrink-0 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">
                {t('common.contract_sign_now')}
            </Link>
        </div>
    );
}
