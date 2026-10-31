<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveLocale;
use App\Services\HomepageCompaniesService;
use App\Services\LegalDocumentService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The public website at cncms.tech: home, about, contact, privacy and terms,
 * in English and French. Plain Blade (resources/views/site), not Inertia,
 * so the pages stay light on slow networks and search engines can read
 * them. Signed-in staff skip the home page and go straight to their work.
 */
class SiteController extends Controller
{
    public function __construct(private readonly LegalDocumentService $legal) {}

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
        $current = $this->legal->current($document);

        if ($current !== null) {
            return $this->legalView($document, $current->version, $current->published_at, $current->body($locale));
        }

        // Nothing published yet (a fresh database): version 1 from the files.
        $path = resource_path("legal/{$locale}/{$document}.md");

        if (! is_file($path)) {
            $path = resource_path("legal/en/{$document}.md");
        }

        return $this->legalView($document, (int) config('site.legal_version'), CarbonImmutable::parse(config('site.legal_effective')), (string) file_get_contents($path));
    }

    /**
     * Also used by the landlord's preview of a draft or an old version.
     */
    public function legalView(string $document, int $version, ?CarbonInterface $effective, string $markdown): View
    {
        return view('site.legal', [
            'documentTitle' => __("site.legal.{$document}"),
            'version' => $version,
            'effective' => ($effective ?? now())->locale(app()->getLocale())->isoFormat('LL'),
            'body' => LegalDocumentService::toHtml($markdown),
        ]);
    }
}
