import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';
import en from '@/lang/en/common.json';
import fr from '@/lang/fr/common.json';

/**
 * react-i18next bootstrap — imported once (for its side effect) from
 * resources/tsx/app.tsx, alongside the existing createInertiaApp() setup.
 *
 * Translation copy is statically imported and bundled at build time (not
 * fetched over HTTP per request/navigation) — see
 * .ai/skills/cncms/cncms-context/references/language-support.md section 2.
 * The active language is driven by the Inertia-shared `locale` prop (set by
 * App\Http\Middleware\ResolveLocale, read via HandleInertiaRequests::share())
 * — see syncLocale() below, called from app.tsx on initial render and on
 * every subsequent Inertia navigation.
 *
 * Each lang/{locale}/*.json file's top-level key names the namespace it
 * lives under within the default 'translation' namespace (e.g. common.json
 * -> { "common": { "dashboard": "...", ... } }), so components call
 * t('common.dashboard') rather than useTranslation('common') + t('dashboard').
 * This keeps every namespace merged into one resource bundle per language,
 * which is simplest at this "prove the mechanism works" stage — revisit
 * only if the per-language bundle size becomes a real concern once all ~33
 * pages are translated (see language-support.md section 7-8).
 */
i18next.use(initReactI18next).init({
    resources: {
        en: { translation: en },
        fr: { translation: fr },
    },
    lng: 'en',
    fallbackLng: 'en',
    supportedLngs: ['en', 'fr'],
    interpolation: {
        // React already escapes rendered output — double-escaping via
        // i18next's own interpolation would mangle accented French text.
        escapeValue: false,
    },
});

/** Kept in sync with App\Http\Middleware\ResolveLocale::SUPPORTED. */
const SUPPORTED_LOCALES = ['en', 'fr'];

/**
 * Switch i18next's active language to match the given locale, if it
 * differs from the current one and is one we actually ship translations
 * for. Called with the Inertia-shared `locale` prop on initial render and
 * after every navigation (see resources/tsx/app.tsx), so a language switch
 * re-renders immediately without a full page reload.
 */
export function syncLocale(locale: unknown): void {
    if (typeof locale !== 'string' || locale === i18next.language || !SUPPORTED_LOCALES.includes(locale)) {
        return;
    }

    void i18next.changeLanguage(locale);
}

export default i18next;
