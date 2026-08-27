import '../css/app.css';
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ErrorBoundary } from '@/components/ui/ErrorBoundary';
import { syncLocale } from '@/lib/i18n';
import type { PageProps } from '@/types';

// Keep i18next's active language matched to the Inertia-shared `locale`
// prop on every subsequent navigation — e.g. after the language switcher
// (resources/tsx/layouts/AppLayout.tsx) redirects back with an updated
// `users.locale`. See resources/tsx/lib/i18n.ts's docblock.
router.on('navigate', (event) => {
    syncLocale((event.detail.page.props as unknown as PageProps).locale);
});

createInertiaApp({
    title: (title) => (title ? `${title} — CNCMS` : 'CNCMS'),
    resolve: (name) =>
        resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        // Sync for the very first render too — router.on('navigate') only
        // fires on subsequent client-side navigations, not the initial
        // full page load.
        syncLocale((props.initialPage.props as unknown as PageProps).locale);

        createRoot(el).render(
            <ErrorBoundary>
                <App {...props} />
            </ErrorBoundary>,
        );
    },
});
