<dl class="contact-list">
    <div><dt>{{ __('site.contact.phone') }}</dt><dd><a href="tel:{{ str_replace(' ', '', config('site.phone')) }}">{{ config('site.phone') }}</a></dd></div>
    <div><dt>{{ __('site.contact.whatsapp') }}</dt><dd><a href="https://wa.me/{{ config('site.whatsapp') }}" target="_blank" rel="noopener">{{ config('site.phone') }}</a></dd></div>
    <div><dt>{{ __('site.contact.email') }}</dt><dd><a href="mailto:{{ config('site.email') }}">{{ config('site.email') }}</a></dd></div>
    <div><dt>{{ __('site.contact.office') }}</dt><dd>{{ __('site.contact.office_value') }}</dd></div></dl>
