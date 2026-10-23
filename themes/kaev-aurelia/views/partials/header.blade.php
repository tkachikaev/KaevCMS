<header class="site-header" data-public-header>
    <div class="container header-inner">
        <a wire:navigate.hover wire:current.exact="active" class="brand" href="{{ public_route('home') }}" aria-label="{{ site_name() }} — {{ __('Go to the home page') }}">
            @if (site_logo_url())
                <img class="brand-logo brand-logo-custom" src="{{ site_logo_url() }}" alt="{{ site_name() }}">
            @else
                <img class="brand-logo brand-logo-kaev" src="{{ theme_asset('images/kaev-logo.png') }}" alt="Kaev">
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
            <a wire:navigate.hover wire:current="active" href="{{ public_route('about') }}">{{ __('About the server') }}</a>
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
        </nav>

        <div class="header-actions">
            <x-language-menu />

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
