<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveLocale;
use App\Services\HomepageCompaniesService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public website at cncms.tech: home, about, contact, privacy and terms,
 * in English and French. Plain Blade (resources/views/site), not Inertia,
 * so the pages stay light on slow networks and search engines can read
 * them. Signed-in staff skip the home page and go straight to their work.
 */
class SiteController extends Controller
{
    public function home(HomepageCompaniesService $companies): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('site.home', ['companies' => $companies->list()]);
    }

    public function about(): View
    {
        return view('site.about');
    }

    public function contact(): View
    {
        return view('site.contact');
    }

    public function privacy(): View
    {
        return $this->legal('privacy');
    }

    public function terms(): View
    {
        return $this->legal('terms');
    }

    /**
     * The EN / FR switch: the choice lives in the session, which
     * ResolveLocale reads first, so the login page follows it too.
     */
    public function language(Request $request, string $locale): RedirectResponse
    {
        if (in_array($locale, ResolveLocale::SUPPORTED, true)) {
            $request->session()->put('locale', $locale);
        }

        return redirect()->back(fallback: route('site.home'));
    }

    /**
     * @param  'privacy'|'terms'  $document
     */
    private function legal(string $document): View
    {
        $locale = app()->getLocale();
        $path = resource_path("legal/{$locale}/{$document}.md");

        if (! is_file($path)) {
            $path = resource_path("legal/en/{$document}.md");
        }

        return view('site.legal', [
            'documentTitle' => __("site.legal.{$document}"),
            'version' => config('site.legal_version'),
            'effective' => CarbonImmutable::parse(config('site.legal_effective'))->locale($locale)->isoFormat('LL'),
            'body' => Str::markdown((string) file_get_contents($path), ['html_input' => 'escape', 'allow_unsafe_links' => false]),
        ]);
    }
}
