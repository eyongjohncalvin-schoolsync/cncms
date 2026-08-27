<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Language switcher (resources/tsx/layouts/AppLayout.tsx header control) —
 * persists the authenticated user's language preference to the central
 * `users.locale` column. Deliberately not folded into Settings/Company (a
 * per-user preference, not a tenant-wide one) or given its own Settings
 * page — a single-field toggle doesn't warrant either. See
 * .ai/skills/cncms/cncms-context/references/language-support.md section 4.
 */
class SettingsLocaleController extends Controller
{
    public function update(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->validated('locale');

        $request->user()->update(['locale' => $locale]);

        // Belt-and-braces: also set the session override so the new locale
        // takes effect immediately on this redirect even before the
        // updated `users.locale` value is re-read from a fresh query on
        // the next request (App\Http\Middleware\ResolveLocale checks the
        // session first, ahead of users.locale, per its resolution order).
        $request->session()->put('locale', $locale);

        return back()->with('success', 'Language updated.');
    }
}
