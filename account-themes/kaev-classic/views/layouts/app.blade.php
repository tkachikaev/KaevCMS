<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ locale_direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#f4efe6">
    @if (site_favicon_url())
        <link rel="icon" href="{{ site_favicon_url() }}">
    @endif
    <title>@yield('title', __('Personal account')) — {{ site_name() }}</title>
    {{-- The font is requested first so text is drawn once in Inter instead of swapping fonts. --}}
    <link rel="preload" href="{{ asset('assets/admin/fonts/inter-variable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <script src="{{ account_theme_asset('assets/js/scheme.js') }}" data-navigate-once></script>
    <script src="{{ account_theme_asset('assets/js/banner-effects.js') }}" defer data-navigate-once></script>
    <link rel="stylesheet" href="{{ account_theme_asset('assets/css/app.css') }}" data-navigate-track>
    @stack('preload')
    <link rel="stylesheet" href="{{ asset('assets/account/css/components.css') }}?v={{ cms_version() }}&ui=account-consistency" data-navigate-track>
    <script src="{{ asset('assets/account/js/navigation.js') }}?v={{ cms_version() }}&ui=account-consistency" defer data-navigate-track data-navigate-once></script>
    @livewireStyles
    @stack('head')
    @includeIf('module-donations::account.assets')
</head>
<body class="account-body">
@php($accountOperation = session('account_operation'))
<div class="account-shell">
    @persist('account-sidebar')
        <aside class="account-sidebar" data-account-sidebar wire:navigate:scroll>
            <a wire:navigate.hover @class(['account-brand', 'has-wide-logo' => site_logo_url() && site_logo_is_wide()]) href="{{ public_route('account') }}" aria-label="{{ site_name() }} — {{ __('Player account') }}">
                @if (site_logo_url())
                    <span class="account-brand-logo"><img src="{{ site_logo_url() }}" alt="{{ site_name() }}"></span>
                @else
                    <span class="account-brand-logo"><img src="{{ asset('assets/images/kaev-logo.webp') }}" alt="{{ site_name() }}"></span>
                @endif
                @unless (site_logo_url() && site_logo_is_wide())
                    <span class="account-brand-copy">
                        <strong title="{{ site_name() }}">{{ site_name() }}</strong>
                        <small>{{ __('Player account') }}</small>
                    </span>
                @endunless
            </a>

            @include('account-theme::partials.navigation')

            <div class="account-sidebar-footer">
                <x-account-server-status />
                <a href="{{ public_route('home') }}"><span aria-hidden="true">←</span>{{ __('Back to website') }}</a>
            </div>
        </aside>
    @endpersist

    <x-account-sidebar-backdrop />

    <main class="account-main">
        {{-- The topbar must rerender on Livewire navigation to show the current wallet balance. --}}
        <header class="account-topbar" data-account-topbar>
            <div class="account-topbar-start">
                <button class="account-sidebar-toggle" type="button" data-account-sidebar-toggle aria-label="{{ __('Player account navigation') }}" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
                {{-- Short page titles speak for themselves; the subtitle line is left out in this theme. --}}
                <div class="account-topbar-context">
                    <h1 class="account-page-title">@yield('title', __('Personal account'))</h1>
                </div>
            </div>

            <div class="account-topbar-actions">
                <x-account-wallet-menu :wallet-view="$walletView" />
                <button type="button" class="account-scheme-toggle" role="switch" aria-checked="false" data-account-scheme-toggle
                    data-label-light="{{ __('Switch to the light theme') }}" data-label-dark="{{ __('Switch to the dark theme') }}"
                    aria-label="{{ __('Switch to the dark theme') }}" title="{{ __('Switch to the dark theme') }}">
                    <span class="account-scheme-track" aria-hidden="true"><span class="account-scheme-thumb">
                        <svg class="account-scheme-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"></path></svg>
                        <svg class="account-scheme-moon" viewBox="0 0 24 24"><path d="M20.2 15.4A8.5 8.5 0 0 1 8.6 3.8 8.5 8.5 0 1 0 20.2 15.4Z"></path></svg>
                    </span></span>
                </button>
                <x-language-menu class="account-language-menu" />
                <details class="account-profile-menu account-profile-menu-topbar">
                    <summary aria-label="{{ __('Open account menu') }}">
                        <x-account-avatar :user="$user" class="account-profile-avatar" aria-hidden="true" />
                        <span class="account-profile-copy"><strong>{{ $user->name }}</strong></span>
                        <span class="account-profile-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="account-profile-dropdown">
                        @include('account-theme::partials.account-menu')
                    </div>
                </details>
            </div>
        </header>

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
@includeIf('module-donations::account.dialog')
@livewireScripts
@stack('scripts')
</body>
</html>
