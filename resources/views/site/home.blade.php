@extends('site.layout', ['title' => 'CNCMS — '.__('site.hero.eyebrow')])

@php
    $icons = [
        'customers' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14.8c1.6.8 2.6 2.5 3 5.2"/>',
        'manuscripts' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'payments' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'app' => '<rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2"/>',
        'cash' => '<path d="M12 3v18M7 7h7.5a3 3 0 0 1 0 6H9a3 3 0 0 0 0 6h8"/>',
        'zones' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'expenses' => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4M9 13l2 2 4-4"/>',
        'complaints' => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
        'roles' => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
    ];
@endphp

@section('masthead')
    <div class="hero">
        <div class="wrap">
            <div>
                <p class="eyebrow" style="color: var(--band-accent)">{{ __('site.hero.eyebrow') }}</p>
                <h1>{!! __('site.hero.title') !!}</h1>
                <p class="lede">{{ __('site.hero.lede') }}</p>
                <div class="ctas">
                    <a class="btn btn-primary" href="{{ route('register') }}">{{ __('site.hero.demo') }}</a>
                    <a class="btn btn-ghost" href="{{ route('login') }}">{{ __('site.nav.login') }}</a>
                </div>
                <p class="fine">{{ __('site.hero.fine') }}</p>
            </div>

            <div class="shot" role="img" aria-label="{{ __('site.hero.shot_label') }}">
                <div class="screen">
                    <div class="screen-top"><i></i><i></i><i></i></div>
                    <div class="screen-body">
                        <div class="screen-head">
                            <h3>{{ __('site.hero.shot_title') }}</h3>
                            <span class="tag">{{ __('site.hero.shot_tag') }}</span>
                        </div>
                        <div class="stats">
                            <div class="stat"><small>{{ __('site.hero.collected') }}</small><strong>4,812,500 frs</strong></div>
                            <div class="stat"><small>{{ __('site.hero.customers') }}</small><strong>2,846</strong></div>
                            <div class="stat"><small>{{ __('site.hero.paid') }}</small><strong>68%</strong></div>
                        </div>
                        <div class="zones">
                            <div class="zone"><span>Kumba Town</span><div class="bar"><b style="width:81%"></b></div><span>81%</span></div>
                            <div class="zone"><span>Fiango</span><div class="bar"><b style="width:74%"></b></div><span>74%</span></div>
                            <div class="zone"><span>Buea Road</span><div class="bar"><b style="width:66%"></b></div><span>66%</span></div>
                            <div class="zone drop"><span>Mambanda</span><div class="bar"><b style="width:49%"></b></div><span>49% ↓</span></div>
                        </div>
                    </div>
                </div>
                <div class="phone" aria-hidden="true">
                    <div class="ok">✓</div>
                    <strong>{{ __('site.hero.phone_title') }}</strong>
                    <div class="amt">2,500 frs</div>
                    <div>{{ __('site.hero.phone_line') }}</div>
                    <div class="wa">{{ __('site.hero.phone_wa') }}</div>
                </div>
                <p class="sample">{{ __('site.hero.sample') }}</p>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="clients">
        <div class="wrap">
            <p>{{ __('site.clients.title') }}</p>
            <ul>
                @foreach ($companies as $company)
                    <li>
                        <span class="logo" aria-hidden="true">
                            @if ($company['logo_url'])
                                <img src="{{ $company['logo_url'] }}" alt="" loading="lazy" width="48" height="48">
                            @else
                                {{ $company['initials'] }}
                            @endif
                        </span>
                        <span><b>{{ $company['name'] }}</b>@if ($company['caption'])<small>{{ $company['caption'] }}</small>@endif</span>
                    </li>
                @endforeach
                <li class="slot">
                    <span class="logo" aria-hidden="true">+</span>
                    <span><b>{{ __('site.clients.slot_title') }}</b><small>{{ __('site.clients.slot_text') }}</small></span>
                </li>
            </ul>
        </div>
    </div>

    <section>
        <div class="wrap">
            <div class="head">
                <p class="eyebrow">{{ __('site.pains.eyebrow') }}</p>
                <h2>{{ __('site.pains.title') }}</h2>
            </div>
            <div class="pains">
                @foreach (__('site.pains.items') as [$heading, $text])
                    <div class="pain"><h3>{{ $heading }}</h3><p>{{ $text }}</p></div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="alt" id="features">
        <div class="wrap">
            <div class="head">
                <p class="eyebrow">{{ __('site.features.eyebrow') }}</p>
                <h2>{{ __('site.features.title') }}</h2>
            </div>
            <div class="features">
                @foreach (__('site.features.items') as $key => [$heading, $text])
                    <div class="feature">
                        <svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons[$key] !!}</svg>
                        <h3>{{ $heading }}</h3>
                        <p>{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section id="app">
        <div class="wrap split">
            <div class="app-phones" role="img" aria-label="{{ __('site.app.label') }}">
                <div class="handset">
                    <div class="hs-bar"><span>{{ __('site.app.zone') }}</span><span class="off">{{ __('site.app.offline') }}</span></div>
                    <div class="hs-search">{{ __('site.app.search') }}</div>
                    <div class="hs-row"><span><b>Ebot Mary</b><small>{{ __('site.app.owes_1') }}</small></span><i>{{ __('site.app.pay') }}</i></div>
                    <div class="hs-row"><span><b>Tabe John</b><small>{{ __('site.app.paid') }}</small></span><i class="done">✓</i></div>
                    <div class="hs-row"><span><b>Nkongho Grace</b><small>{{ __('site.app.owes_2') }}</small></span><i>{{ __('site.app.pay') }}</i></div>
                    <div class="hs-row"><span><b>Ashu Peter</b><small>{{ __('site.app.cut') }}</small></span><i class="warn">!</i></div>
                    <div class="hs-tabs">
                        @foreach (__('site.app.tabs') as $index => $tab)
                            <span @class(['on' => $index === 1])>{{ $tab }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div>
                <p class="eyebrow">{{ __('site.app.eyebrow') }}</p>
                <h2 class="sec-title">{{ __('site.app.title') }}</h2>
                <p class="sec-lede">{{ __('site.app.lede') }}</p>
                <ul class="app-list">
                    @foreach (__('site.app.items') as [$heading, $text])
                        <li><b>{{ $heading }}</b><span>{{ $text }}</span></li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    <div class="local">
        <div class="wrap">
            @foreach (__('site.local') as [$heading, $text])
                <div><b>{{ $heading }}</b><span>{{ $text }}</span></div>
            @endforeach
        </div>
    </div>

    <section id="how">
        <div class="wrap">
            <div class="head">
                <p class="eyebrow">{{ __('site.how.eyebrow') }}</p>
                <h2>{{ __('site.how.title') }}</h2>
                <p>{{ __('site.how.lede') }}</p>
            </div>
            <ol class="steps">
                @foreach (__('site.how.steps') as [$heading, $text])
                    <li class="step"><h3>{{ $heading }}</h3><p>{{ $text }}</p></li>
                @endforeach
            </ol>
            <div class="assist">
                <div class="assist-chat" role="img" aria-label="{{ __('site.how.guide_label') }}">
                    <p class="assist-who">{{ __('site.how.guide_who') }}</p>
                    <p class="assist-msg">{{ __('site.how.guide_msg') }}</p>
                    <div class="assist-btns"><span>{{ __('site.how.guide_b1') }}</span><span>{{ __('site.how.guide_b2') }}</span></div>
                </div>
                <div>
                    <h3>{{ __('site.how.guide_title') }}</h3>
                    <p>{{ __('site.how.guide_text') }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="alt" id="security">
        <div class="wrap split">
            <div>
                <p class="eyebrow">{{ __('site.security.eyebrow') }}</p>
                <h2 class="sec-title">{{ __('site.security.title') }}</h2>
                <ul class="checks">
                    @foreach (__('site.security.items') as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="panel">
                <p class="eyebrow">{{ __('site.security.log_title') }}</p>
                <div class="log">
                    @foreach (__('site.security.log') as [$time, $text])
                        <div><time>{{ $time }}</time><span>{{ $text }}</span></div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="wrap quote">
            <blockquote>{{ __('site.quote.text') }}</blockquote>
            <cite>{{ __('site.quote.by') }}</cite>
        </div>
    </section>

    <section class="alt" id="faq">
        <div class="wrap faq-wrap">
            <div class="head">
                <p class="eyebrow">{{ __('site.faq.eyebrow') }}</p>
                <h2>{{ __('site.faq.title') }}</h2>
            </div>
            <div class="faq">
                @foreach (__('site.faq.items') as $index => [$question, $answer])
                    <details @if ($index === 0) open @endif>
                        <summary>{{ $question }}</summary>
                        <p>{{ str_replace(':phone', config('site.phone'), $answer) }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    @include('site.partials.demo')
@endsection
