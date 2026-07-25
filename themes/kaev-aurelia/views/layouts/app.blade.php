<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ locale_direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f8f4ec">
    @hasSection('meta_description')
        <meta name="description" content="@yield('meta_description')">
    @elseif (site_description() !== '')
        <meta name="description" content="{{ site_description() }}">
    @endif
    @if (! empty($canonicalUrl))
        <link rel="canonical" href="{{ $canonicalUrl }}">
    @endif
    @foreach (($alternateUrls ?? []) as $alternateLocale => $alternateUrl)
        <link rel="alternate" hreflang="{{ $alternateLocale }}" href="{{ $alternateUrl }}">
    @endforeach
    @if (! empty($defaultAlternateUrl))
        <link rel="alternate" hreflang="x-default" href="{{ $defaultAlternateUrl }}">
    @endif
    @if (site_favicon_url())
        <link rel="icon" href="{{ site_favicon_url() }}">
    @else
        <link rel="icon" type="image/png" href="{{ theme_asset('images/kaev-mark.png') }}">
    @endif
    @if (! empty($isPreview))
        <meta name="robots" content="noindex, nofollow, noarchive">
    @endif
    <title>@yield('title', site_name())</title>
    <link rel="stylesheet" href="{{ theme_asset('css/app.css') }}" data-navigate-track>
    <script src="{{ theme_asset('js/app.js') }}" defer data-navigate-track data-navigate-once></script>
    @livewireStyles
    @stack('head')
</head>
<body class="kaev-aurelia">
    <div class="page-grain" aria-hidden="true"></div>
    <div class="page-aura" aria-hidden="true"></div>

    @if (! empty($isPreview))
        <aside class="news-preview-banner" role="status">
            <strong>{{ ($previewKind ?? 'news') === 'page' ? __('Preview page') : __('Preview news') }}</strong>
            <span>{{ __('Changes are not saved or published. Close this tab after checking the page.') }}</span>
        </aside>
    @endif

    @persist('public-header')
        @include('theme::partials.header')
    @endpersist

    @if (session('status') || session('warning') || $errors->any())
        <div class="container public-flash-stack">
            @if (session('status'))
                <div class="public-flash public-flash-success" role="status">{{ session('status') }}</div>
            @endif
            @if (session('warning'))
                <div class="public-flash public-flash-warning" role="alert">{{ session('warning') }}</div>
            @endif
            @if ($errors->any())
                <div class="public-flash public-flash-error" role="alert">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <main id="public-content" tabindex="-1">@yield('content')</main>
    @include('theme::partials.footer')

    @livewireScripts
    @stack('scripts')
</body>
</html>
