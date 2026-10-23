{{-- The site logo from Settings → Site when one is uploaded, otherwise the first letter of the name. --}}
@php($brandLogoUrl = site_logo_url())
@if($brandLogoUrl)
    <span class="{{ $class }} has-logo" aria-hidden="true"><img src="{{ $brandLogoUrl }}" alt=""></span>
@else
    <span class="{{ $class }}" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) config('app.name'), 0, 1)) }}</span>
@endif
