@extends('site.layout', ['title' => __('site.about.title').' — CNCMS', 'description' => __('site.about.lede')])

@section('masthead')
    <div class="page-head">
        <div class="wrap">
            <h1>{{ __('site.about.title') }}</h1>
            <p>{{ __('site.about.lede') }}</p>
        </div>
    </div>
@endsection

@section('content')
    <section>
        <div class="wrap">
            <div class="prose">
                @foreach (__('site.about.body') as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>

            <h2 class="sec-title" style="margin-top: 56px">{{ __('site.about.values_title') }}</h2>
            <div class="values">
                @foreach (__('site.about.values') as [$heading, $text])
                    <div><b>{{ $heading }}</b><span>{{ $text }}</span></div>
                @endforeach
            </div>
        </div>
    </section>

    @include('site.partials.demo')
@endsection
