<section class="demo" id="demo">
    <div class="wrap split">
        <div>
            <p class="eyebrow">{{ __('site.demo.eyebrow') }}</p>
            <h2>{{ __('site.demo.title') }}</h2>
            <p style="margin-top: 14px; font-size: 17px">{{ __('site.demo.lede') }}</p>
            <div class="ctas">
                <a class="btn btn-primary" href="{{ route('register') }}">{{ __('site.demo.cta') }}</a>
                <a class="btn btn-ghost" href="{{ route('login') }}">{{ __('site.nav.login') }}</a>
            </div>
        </div>
        <div class="panel">
            <p style="margin-bottom: 16px">{{ __('site.demo.call') }}</p>
            @include('site.partials.contact-list')
        </div>
    </div>
</section>
