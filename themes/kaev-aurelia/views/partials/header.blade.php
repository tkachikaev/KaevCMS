<header class="site-header" data-public-header>
    <div class="container header-inner">
        <a wire:navigate.hover wire:current.exact="active" class="brand" href="{{ public_route('home') }}" aria-label="{{ site_name() }} — {{ __('Go to the home page') }}">
            @if (site_logo_url())
                <img class="brand-logo brand-logo-custom" src="{{ site_logo_url() }}" alt="{{ site_name() }}">
            @else
                <img class="brand-logo brand-logo-kaev" src="{{ theme_asset('images/kaev-logo.webp') }}" alt="Kaev">
            @endif
        </a>

        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="main-menu" data-menu-toggle>
            <span></span><span></span><span></span><b class="sr-only">{{ __('Menu') }}</b>
        </button>

        <nav id="main-menu" class="main-nav" aria-label="{{ __('Main navigation') }}" data-main-menu>
            <a wire:navigate.hover wire:current.exact="active" href="{{ public_route('home') }}">{{ __('Home') }}</a>
            <a wire:navigate.hover wire:current="active" href="{{ public_route('news.index') }}">{{ __('News') }}</a>
            @if($statisticsNavigationAvailable ?? false)
                <a wire:navigate.hover wire:current="active" href="{{ public_route('statistics.index') }}">{{ __('Statistics') }}</a>
            @endif
            <a wire:navigate.hover wire:current="active" href="{{ page_key_url('about') }}">{{ __('About the server') }}</a>
            @foreach ($headerPages ?? [] as $menuPage)
                <a wire:navigate.hover wire:current="active" href="{{ page_url($menuPage) }}">{{ $menuPage->titleFor() }}</a>
            @endforeach
            @foreach ($headerCustomLinks ?? [] as $customLink)
                <a href="{{ $customLink->url }}" @if ($customLink->open_new_tab) target="_blank" rel="noopener noreferrer" @endif>{{ $customLink->titleFor() }}</a>
            @endforeach
            @auth
                {{-- The account has a separate layout: load its styles before painting. --}}
                <a class="mobile-account-link" href="{{ public_route('account') }}">{{ __('Account') }}</a>
            @else
                <a wire:navigate.hover wire:current.exact="active" class="mobile-account-link" href="{{ public_route('login') }}">{{ __('Sign in') }}</a>
                @if (registration_available())
                    <a wire:navigate.hover wire:current.exact="active" class="mobile-account-link" href="{{ public_route('register') }}">{{ __('Register') }}</a>
                @endif
            @endauth
            {{-- On phones the header actions are hidden, so the menu carries the language choice. --}}
            <x-language-menu class="mobile-language-menu" />
            <button type="button" class="mobile-scheme-toggle" role="switch" aria-checked="false" data-site-scheme-toggle
                data-label-light="{{ __('Switch to the light theme') }}" data-label-dark="{{ __('Switch to the dark theme') }}"
                data-dark-art="{{ theme_asset('images/hero-dark.webp') }}" data-light-art="{{ theme_asset('images/hero.webp') }}" data-light-art-mobile="{{ theme_asset('images/hero-mobile.webp') }}"
                aria-label="{{ __('Switch to the dark theme') }}">
                <span class="scheme-track" aria-hidden="true"><span class="scheme-thumb">
                    <svg class="scheme-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"></path></svg>
                    <svg class="scheme-moon" viewBox="0 0 24 24"><path d="M20.2 15.4A8.5 8.5 0 0 1 8.6 3.8 8.5 8.5 0 1 0 20.2 15.4Z"></path></svg>
                </span></span>
                <span data-scheme-label>{{ __('Switch to the dark theme') }}</span>
            </button>
        </nav>

        <div class="header-actions">
            <x-language-menu />
            <button type="button" class="scheme-toggle" role="switch" aria-checked="false" data-site-scheme-toggle
                data-label-light="{{ __('Switch to the light theme') }}" data-label-dark="{{ __('Switch to the dark theme') }}"
                data-dark-art="{{ theme_asset('images/hero-dark.webp') }}" data-light-art="{{ theme_asset('images/hero.webp') }}" data-light-art-mobile="{{ theme_asset('images/hero-mobile.webp') }}"
                aria-label="{{ __('Switch to the dark theme') }}" title="{{ __('Switch to the dark theme') }}">
                <span class="scheme-track" aria-hidden="true"><span class="scheme-thumb">
                    <svg class="scheme-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"></path></svg>
                    <svg class="scheme-moon" viewBox="0 0 24 24"><path d="M20.2 15.4A8.5 8.5 0 0 1 8.6 3.8 8.5 8.5 0 1 0 20.2 15.4Z"></path></svg>
                </span></span>
            </button>

            @auth
                <a class="button button-gold header-main-action" href="{{ public_route('account') }}">{{ __('Account') }}</a>
                <form class="header-logout-form" method="POST" action="{{ public_route('logout') }}">
                    @csrf
                    <button class="header-text-action" type="submit">{{ __('Sign out') }}</button>
                </form>
            @else
                <a wire:navigate.hover wire:current.exact="active" class="header-text-action" href="{{ public_route('login') }}">{{ __('Sign in') }}</a>
                @if (registration_available())
                    <a wire:navigate.hover wire:current.exact="active" class="button button-gold header-main-action" href="{{ public_route('register') }}">{{ __('Register') }}</a>
                @endif
            @endauth
        </div>
    </div>
</header>
