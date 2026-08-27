import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const LOCALES = [
    { code: 'en', label: 'EN' },
    { code: 'fr', label: 'FR' },
] as const;

/**
 * Header language switcher (resources/tsx/layouts/AppLayout.tsx) — persists
 * the choice to the authenticated user's `users.locale` column via
 * PATCH /settings/locale (App\Http\Controllers\SettingsLocaleController),
 * then relies on resources/tsx/app.tsx's router.on('navigate') hook to call
 * i18next.changeLanguage() from the redirect's updated `locale` shared prop
 * — no full page reload needed for the switch to become visible. See
 * .ai/skills/cncms/cncms-context/references/language-support.md section 4.
 */
export function LanguageSwitcher() {
    const { t, i18n } = useTranslation();

    function switchTo(code: string) {
        if (code === i18n.language) {
            return;
        }

        router.patch(
            '/settings/locale',
            { locale: code },
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <div
            className="flex items-center overflow-hidden rounded-md border border-slate-200"
            role="group"
            aria-label={t('common.language')}
        >
            {LOCALES.map(({ code, label }) => {
                const active = i18n.language === code;

                return (
                    <button
                        key={code}
                        type="button"
                        onClick={() => switchTo(code)}
                        className={`px-2 py-1.5 text-xs font-semibold transition-colors ${
                            active ? 'bg-blue-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-700'
                        }`}
                        aria-pressed={active}
                    >
                        {label}
                    </button>
                );
            })}
        </div>
    );
}
