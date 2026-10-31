import { useTranslation } from 'react-i18next';
import { IconBrandWhatsapp, IconHourglass, IconLock } from '@tabler/icons-react';
import type { DemoNotice } from '@/types';

/**
 * The shared `demo` prop (HandleInertiaRequests::demoNotice): amber
 * countdown for the company's super/admin in the demo's last 30 days, then
 * a red read-only strip for everyone once it has ended (App\Support\
 * DemoReadOnly refuses every save). Rendered by AppLayout.
 */
export function DemoBanner({ notice }: { notice: DemoNotice | null | undefined }) {
    const { t, i18n } = useTranslation();

    if (!notice) {
        return null;
    }

    const date = new Date(`${notice.ends_on}T12:00:00`).toLocaleDateString(i18n.language, { day: 'numeric', month: 'long', year: 'numeric' });
    const title = notice.read_only
        ? t('common.demo_ended_title')
        : notice.days_left === 0
          ? t('common.demo_ending_today')
          : t('common.demo_ending_title', { count: notice.days_left });
    const Icon = notice.read_only ? IconLock : IconHourglass;

    return (
        <div
            role="status"
            className={`flex items-start gap-2.5 border-b px-4 py-2.5 text-sm md:px-6 ${
                notice.read_only ? 'border-red-800 bg-red-700 text-white' : 'border-amber-300 bg-amber-100 text-amber-900'
            }`}
        >
            <Icon size={18} stroke={1.75} className="mt-0.5 shrink-0" />
            <div className="min-w-0 flex-1">
                <p className="font-semibold">{title}</p>
                <p className={notice.read_only ? 'text-red-100' : 'text-amber-800'}>
                    {notice.read_only ? t('common.demo_ended_body') : t('common.demo_ending_body', { date })}
                </p>
            </div>
            <a
                href={`https://wa.me/${notice.whatsapp}`}
                target="_blank"
                rel="noopener noreferrer"
                className={`inline-flex shrink-0 items-center gap-1.5 self-center rounded-lg px-3 py-1.5 text-xs font-semibold ${
                    notice.read_only ? 'bg-white text-red-700 hover:bg-red-50' : 'bg-amber-600 text-white hover:bg-amber-700'
                }`}
            >
                <IconBrandWhatsapp size={16} stroke={1.75} />
                <span className="hidden sm:inline">{t('common.demo_contact', { phone: notice.phone })}</span>
            </a>
        </div>
    );
}
