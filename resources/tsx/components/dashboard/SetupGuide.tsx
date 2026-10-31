import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { IconBrandWhatsapp, IconCircleCheckFilled, IconCircleDashed, IconRocket, IconX } from '@tabler/icons-react';
import type { PageProps } from '@/types';

export interface SetupGuideData {
    steps: { key: 'zones' | 'customers' | 'staff' | 'agent_app' | 'first_month'; done: boolean; href: string }[];
    phone: string;
    whatsapp: string;
    app_url: string | null;
}

/**
 * The new company's setup checklist (App\Services\SetupGuideService) at the
 * top of the dashboard, for the super/admin: each step ticks itself from the
 * company's data; the first open step is expanded with how to do it.
 */
export function SetupGuide({ guide }: { guide: SetupGuideData }) {
    const { t } = useTranslation();
    const { company } = usePage<PageProps>().props;
    const done = guide.steps.filter((step) => step.done).length;
    const nextKey = guide.steps.find((step) => !step.done)?.key;

    function hide() {
        router.post('/setup-guide/hide', {}, { preserveScroll: true });
    }

    return (
        <section className="animate-fade-up mb-6 rounded-xl border border-indigo-200 bg-white shadow-sm">
            <div className="flex items-start gap-3 border-b border-indigo-100 px-4 py-3 md:px-5">
                <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconRocket size={18} stroke={1.75} />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="font-display text-lg font-semibold text-slate-900">{t('setup.title', { company: company?.name ?? 'CNCMS' })}</h2>
                    <p className="text-sm text-slate-500">{t('setup.progress', { done, total: guide.steps.length })}</p>
                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div className="h-full rounded-full bg-indigo-600 transition-all" style={{ width: `${(done / guide.steps.length) * 100}%` }} />
                    </div>
                </div>
                <button type="button" onClick={hide} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label={t('setup.hide')} title={t('setup.hide')}>
                    <IconX size={18} />
                </button>
            </div>

            <ol className="divide-y divide-slate-100">
                {guide.steps.map((step, index) => {
                    const open = step.key === nextKey;

                    return (
                        <li key={step.key} className={`flex gap-3 px-4 py-3 md:px-5 ${open ? 'bg-indigo-50/50' : ''}`}>
                            {step.done ? (
                                <IconCircleCheckFilled size={22} className="mt-0.5 shrink-0 text-green-600" aria-label={t('setup.done')} />
                            ) : (
                                <IconCircleDashed size={22} stroke={1.75} className={`mt-0.5 shrink-0 ${open ? 'text-indigo-600' : 'text-slate-300'}`} />
                            )}
                            <div className="min-w-0 flex-1">
                                <p className={`text-sm font-medium ${step.done ? 'text-slate-400 line-through' : 'text-slate-900'}`}>
                                    {index + 1}. {t(`setup.steps.${step.key}.title`)}
                                </p>
                                {open && (
                                    <>
                                        <p className="mt-1 text-sm text-slate-600">{t(`setup.steps.${step.key}.body`)}</p>
                                        <div className="mt-2 flex flex-wrap items-center gap-2">
                                            <Link href={step.href} className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                                {t(`setup.steps.${step.key}.action`)}
                                            </Link>
                                            {step.key === 'agent_app' && guide.app_url && (
                                                <a href={guide.app_url} target="_blank" rel="noopener noreferrer" className="text-xs font-medium text-indigo-700 underline">
                                                    {t('setup.download_app')}
                                                </a>
                                            )}
                                        </div>
                                    </>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ol>

            <div className="flex items-center gap-2 border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500 md:px-5">
                <IconBrandWhatsapp size={16} stroke={1.75} className="text-emerald-600" />
                <span>
                    {t('setup.help')}{' '}
                    <a href={`https://wa.me/${guide.whatsapp}`} target="_blank" rel="noopener noreferrer" className="font-medium text-emerald-700 hover:underline">
                        {guide.phone}
                    </a>
                </span>
            </div>
        </section>
    );
}
