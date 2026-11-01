<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateThemeRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Light / Dark / Match my device (docs/plans/mobile-manager-app.md,
 * wave 7) — saved on the person's own `users.ui_theme` (null = match the
 * device), so it follows them to any browser. The page has already
 * switched by the time this answers (ThemeSwitcher.tsx); app.blade.php
 * applies the saved choice before the first paint of every later page.
 */
class SettingsThemeController extends Controller
{
    public function update(UpdateThemeRequest $request): RedirectResponse
    {
        $theme = $request->validated('theme');

        $request->user()->update(['ui_theme' => $theme === 'system' ? null : $theme]);

        return back();
    }
}
