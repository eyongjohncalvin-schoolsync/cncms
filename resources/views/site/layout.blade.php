{{-- Public website shell (App\Http\Controllers\SiteController). Plain Blade, no React: fast on slow networks and readable by search engines. --}}
@php
    $locale = app()->getLocale();
    $otherLocale = $locale === 'fr' ? 'en' : 'fr';
    $phone = config('site.phone');
    $whatsapp = 'https://wa.me/'.config('site.whatsapp');
    $isHome = request()->routeIs('site.home');
    $anchor = fn (string $id) => $isHome ? "#{$id}" : route('site.home')."#{$id}";
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description ?? __('site.meta_description') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description ?? __('site.meta_description') }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="theme-color" content="#0d1b33">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    {{ \Illuminate\Support\Facades\Vite::fonts() }}
    @vite(['resources/css/site.css'])
</head>
<body>
    <a class="skip" href="#main">{{ __('site.skip') }}</a>

    <div class="mast">
        <header class="nav">
            <div class="wrap">
                <a class="brand" href="{{ route('site.home') }}"><b>CNCMS</b><span>{{ __('site.brand_by') }}</span></a>
                <nav class="nav-links" aria-label="{{ __('site.nav.menu') }}">
                    <a href="{{ $anchor('features') }}">{{ __('site.nav.features') }}</a>
                    <a href="{{ $anchor('app') }}">{{ __('site.nav.app') }}</a>
                    <a href="{{ $anchor('how') }}">{{ __('site.nav.how') }}</a>
                    <a href="{{ route('site.about') }}" @if (request()->routeIs('site.about')) aria-current="page" @endif>{{ __('site.nav.about') }}</a>
                    <a href="{{ route('site.contact') }}" @if (request()->routeIs('site.contact')) aria-current="page" @endif>{{ __('site.nav.contact') }}</a>
                </nav>
                <div class="nav-actions">
                    <div class="lang" role="group" aria-label="{{ __('site.nav.language') }}">
                        <a href="{{ route('site.language', 'en') }}" hreflang="en" lang="en" @if ($locale === 'en') aria-current="true" @endif>EN</a>
                        <a href="{{ route('site.language', 'fr') }}" hreflang="fr" lang="fr" @if ($locale === 'fr') aria-current="true" @endif>FR</a>
                    </div>
                    <a class="btn btn-ghost" href="{{ route('login') }}">{{ __('site.nav.login') }}</a>
                    <a class="btn btn-primary" href="{{ route('register') }}">{{ __('site.nav.demo') }}</a>
                    <details class="menu">
                        <summary>{{ __('site.nav.menu') }}</summary>
                        <nav aria-label="{{ __('site.nav.menu') }}">
                            <a href="{{ $anchor('features') }}">{{ __('site.nav.features') }}</a>
                            <a href="{{ $anchor('app') }}">{{ __('site.nav.app') }}</a>
                            <a href="{{ $anchor('how') }}">{{ __('site.nav.how') }}</a>
                            <a href="{{ route('site.about') }}">{{ __('site.nav.about') }}</a>
                            <a href="{{ route('site.contact') }}">{{ __('site.nav.contact') }}</a>
                            <a href="{{ route('login') }}">{{ __('site.nav.login') }}</a>
                            <a href="{{ route('register') }}">{{ __('site.nav.demo') }}</a>
                        </nav>
                    </details>
                </div>
            </div>
        </header>

        @yield('masthead')
    </div>

    <main id="main">
        @yield('content')
    </main>

    <footer>
        <div class="wrap">
            <span>{{ __('site.footer.rights', ['year' => now()->year]) }} · {{ $phone }} · {{ config('site.email') }}</span>
            <nav aria-label="Footer">
                <a href="{{ route('site.about') }}">{{ __('site.nav.about') }}</a>
                <a href="{{ route('site.contact') }}">{{ __('site.nav.contact') }}</a>
                <a href="{{ route('site.privacy') }}">{{ __('site.footer.privacy') }}</a>
                <a href="{{ route('site.terms') }}">{{ __('site.footer.terms') }}</a>
                <a href="{{ route('login') }}">{{ __('site.nav.login') }}</a>
            </nav>
        </div>
    </footer>

    <a class="wa-float" href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="WhatsApp {{ config('site.company') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.2a8.7 8.7 0 0 0-7.5 13.1L3.3 20.8l4.6-1.2A8.7 8.7 0 1 0 12 3.2Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 8.3c.2-.4.4-.4.7-.4h.5c.2 0 .4.1.5.4l.7 1.6c.1.2 0 .4-.1.6l-.5.6c.6 1.1 1.4 1.9 2.5 2.5l.6-.5c.2-.2.4-.2.6-.1l1.6.7c.3.1.4.3.4.5v.5c0 .3-.1.5-.4.7-.5.3-1.2.5-1.9.3-2.6-.7-4.6-2.7-5.3-5.3-.2-.7 0-1.4.3-1.9Z" fill="currentColor"/></svg>
        <span>{{ __('site.whatsapp_float') }}</span>
    </a>
</body>
</html>
