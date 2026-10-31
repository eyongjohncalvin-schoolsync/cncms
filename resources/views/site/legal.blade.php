@extends('site.layout', ['title' => $documentTitle.' — CNCMS'])

@section('masthead')
    <div class="page-head">
        <div class="wrap">
            <h1>{{ $documentTitle }}</h1>
        </div>
    </div>
@endsection

@section('content')
    <section>
        <div class="wrap">
            <p class="doc-meta">
                {{ __('site.legal.version', ['version' => $version, 'date' => $effective]) }}<br>
                {{ __('site.legal.questions', ['email' => config('site.email')]) }}
            </p>
            <article class="prose">{!! $body !!}</article>
        </div>
    </section>
@endsection
