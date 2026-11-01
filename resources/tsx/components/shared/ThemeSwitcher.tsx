import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { IconDeviceDesktop, IconMoon, IconSun } from '@tabler/icons-react';
import type { PageProps, ThemeChoice } from '@/types';

const DARK_QUERY = '(prefers-color-scheme: dark)';

/** Puts the choice on <html>: the `dark` class drives resources/css/dark.css. */
function apply(choice: ThemeChoice): void {
    const dark = choice === 'dark' || (choice === 'system' && window.matchMedia(DARK_QUERY).matches);

    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.theme = choice;
}

/**
 * Header light/dark switch (docs/plans/mobile-manager-app.md, wave 7),
 * beside the language switcher. The page changes at once; the choice is
 * saved on the person (PATCH /settings/theme) so every later page —
 * rendered dark from the start by app.blade.php — and every browser they
 * sign in on follows it. "Match my device" also follows the device when
 * it changes while the page is open.
 */
export function ThemeSwitcher() {
    const { t } = useTranslation();
    const saved = usePage<PageProps>().props.auth.user?.ui_theme ?? 'system';
    const [choice, setChoice] = useState<ThemeChoice>(saved);

    useEffect(() => {
        if (choice !== 'system') {
            return;
        }

        const media = window.matchMedia(DARK_QUERY);
        const follow = () => apply('system');
        media.addEventListener('change', follow);

        return () => media.removeEventListener('change', follow);
    }, [choice]);

    function choose(next: ThemeChoice) {
        if (next === choice) {
            return;
        }

        setChoice(next);
        apply(next);
        router.patch('/settings/theme', { theme: next }, { preserveScroll: true, preserveState: true });
    }

    const options: { value: ThemeChoice; label: string; icon: typeof IconSun }[] = [
        { value: 'light', label: t('common.theme_light'), icon: IconSun },
        { value: 'dark', label: t('common.theme_dark'), icon: IconMoon },
        { value: 'system', label: t('common.theme_system'), icon: IconDeviceDesktop },
    ];

    return (
        <div className="flex items-center overflow-hidden rounded-md border border-slate-200" role="group" aria-label={t('common.theme')}>
            {options.map(({ value, label, icon: Icon }) => {
                const active = choice === value;

                return (
                    <button
                        key={value}
                        type="button"
                        onClick={() => choose(value)}
                        title={label}
                        aria-label={label}
                        aria-pressed={active}
                        className={`px-2 py-1.5 transition-colors ${active ? 'bg-blue-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-700'}`}
                    >
                        <Icon size={14} stroke={2} aria-hidden="true" />
                    </button>
                );
            })}
        </div>
    );
}
