<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ locale_direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#f4f1e8">
    @if (site_favicon_url())
        <link rel="icon" href="{{ site_favicon_url() }}">
    @else
        <link rel="icon" type="image/png" href="{{ account_theme_asset('assets/images/kaev-mark.png') }}">
    @endif
    <title>@yield('title', __('Personal account')) — {{ site_name() }}</title>
    <link rel="stylesheet" href="{{ account_theme_asset('assets/css/app.css') }}" data-navigate-track>
    <script src="{{ asset('assets/account/js/navigation.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
    @livewireStyles
    @stack('head')
</head>
<body class="account-body">
@php($accountOperation = session('account_operation'))
<div class="account-page-grid" aria-hidden="true"></div>
<div class="account-page-orbit" aria-hidden="true"></div>

<div class="account-shell">
    @persist('account-sidebar')
        <aside class="account-sidebar" data-account-sidebar wire:navigate:scroll>
            <div class="account-sidebar-glow" aria-hidden="true"></div>

            <a wire:navigate.hover class="account-brand" href="{{ public_route('account') }}" aria-label="{{ site_name() }} — {{ __('Player account') }}">
                @if (site_logo_url())
                    <span class="account-brand-logo account-brand-logo-custom"><img src="{{ site_logo_url() }}" alt="{{ site_name() }}"></span>
                @else
                    <span class="account-brand-logo"><img src="{{ account_theme_asset('assets/images/kaev-logo.png') }}" alt="Kaev"></span>
                @endif
                <span class="account-brand-copy">
                    <strong>{{ site_name() }}</strong>
                    <small>{{ __('Player account') }}</small>
                </span>
            </a>

            @include('account-theme::partials.navigation')

            <div class="account-sidebar-footer">
                <a href="{{ public_route('home') }}"><span aria-hidden="true">←</span>{{ __('Back to website') }}</a>
                <div><span>{{ __('Account theme') }}</span><strong>{{ $activeAccountTheme['name'] ?? '—' }}</strong></div>
                <small>{{ __('Version :version', ['version' => cms_version()]) }}</small>
            </div>
        </aside>
    @endpersist

    <main class="account-main">
        @persist('account-topbar')
            <header class="account-topbar" data-account-topbar>
                <div class="account-topbar-start">
                    <button class="account-sidebar-toggle" type="button" data-account-sidebar-toggle aria-label="{{ __('Player account navigation') }}" aria-expanded="false">
                        <span></span><span></span><span></span>
                    </button>
                    <div class="account-topbar-context">
                        <span>{{ __('Player account') }}</span>
                        <strong>{{ $user->name }}</strong>
                    </div>
                </div>

                <div class="account-topbar-actions">
                    @if(count($enabledLanguages ?? []) > 1)
                        <div class="account-language-switcher" aria-label="{{ __('Switch language') }}">
                            @foreach($enabledLanguages as $code => $language)
                                <a class="{{ app()->getLocale() === $code ? 'active' : '' }}" href="{{ route('language.switch', ['locale' => $code, 'return' => request()->getRequestUri()]) }}" lang="{{ $code }}" hreflang="{{ $code }}" data-no-navigate>{{ strtoupper($code) }}</a>
                            @endforeach
                        </div>
                    @endif
                    <details class="account-profile-menu account-profile-menu-topbar">
                        <summary aria-label="{{ __('Open account menu') }}">
                            <x-account-avatar :user="$user" class="account-profile-avatar" aria-hidden="true" />
                            <span class="account-profile-copy"><strong>{{ $user->name }}</strong><small>{{ __('Account settings') }}</small></span>
                            <span class="account-profile-chevron" aria-hidden="true">⌄</span>
                        </summary>
                        <div class="account-profile-dropdown">
                            @include('account-theme::partials.account-menu')
                        </div>
                    </details>
                </div>
            </header>
        @endpersist

        <div class="account-content" data-account-content>
            @if (session('status') && ! $accountOperation)
                <div class="account-notice success" role="status"><span aria-hidden="true">✓</span><div>{{ session('status') }}</div></div>
            @endif
            @if (session('warning'))
                <div class="account-notice warning" role="alert"><span aria-hidden="true">!</span><div>{{ session('warning') }}</div></div>
            @endif
            @if ($errors->any() && ! $accountOperation && ! trim($__env->yieldContent('inline-validation-errors')))
                <div class="account-notice error" role="alert">
                    <span aria-hidden="true">!</span>
                    <div>@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
                </div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
<x-account-avatar-modal :user="$user" />
<x-account-operation-modal :payload="$accountOperation" />
@livewireScripts
@stack('scripts')
</body>
</html>
