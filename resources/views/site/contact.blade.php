@extends('site.layout', ['title' => __('site.contact.title').' — CNCMS', 'description' => __('site.contact.lede')])

@section('masthead')
    <div class="page-head">
        <div class="wrap">
            <h1>{{ __('site.contact.title') }}</h1>
            <p>{{ __('site.contact.lede') }}</p>
        </div>
    </div>
@endsection

@section('content')
    <section>
        <div class="wrap contact-grid">
            <div class="panel">
                @include('site.partials.contact-list')
                <div style="margin-top: 24px">
                    <a class="btn btn-primary" href="https://wa.me/{{ config('site.whatsapp') }}" target="_blank" rel="noopener">{{ __('site.contact.chat') }}</a>
                </div>
            </div>
            <div>
                <h2 class="sec-title" style="margin-top: 0">{{ __('site.contact.support_title') }}</h2>
                <p class="sec-lede">{{ __('site.contact.support_text') }}</p>
                <div style="margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap">
                    <a class="btn btn-outline" href="{{ route('login') }}">{{ __('site.nav.login') }}</a>
                    <a class="btn btn-primary" href="{{ route('register') }}">{{ __('site.demo.cta') }}</a>
                </div>
            </div>
        </div>
    </section>
@endsection
