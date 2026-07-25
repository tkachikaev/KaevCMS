<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="{{ public_route('home') }}" aria-label="{{ site_name() }} — {{ __('Go to the home page') }}">
            @if (site_logo_url())
                <img class="brand-logo" src="{{ site_logo_url() }}" alt="{{ site_name() }}">
            @else
                <span class="brand-mark">L2</span>
                <span><strong>{{ site_name() }}</strong><small>LINEAGE II</small></span>
            @endif
        </a>
        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="main-menu">{{ __('Menu') }}</button>
        <nav id="main-menu" class="main-nav" aria-label="{{ __('Main navigation') }}">
            <a class="{{ request()->routeIs('home', 'localized.home') ? 'active' : '' }}" href="{{ public_route('home') }}">{{ __('Home') }}</a>
            <a class="{{ request()->routeIs('news.*', 'localized.news.*') ? 'active' : '' }}" href="{{ public_route('news.index') }}">{{ __('News') }}</a>
            @if($statisticsNavigationAvailable ?? false)
                <a class="{{ request()->routeIs('statistics.*', 'localized.statistics.*') ? 'active' : '' }}" href="{{ public_route('statistics.index') }}">{{ __('Statistics') }}</a>
            @endif
            <a class="{{ request()->routeIs('about', 'localized.about') ? 'active' : '' }}" href="{{ public_route('about') }}">{{ __('About the server') }}</a>
            @foreach ($headerPages ?? [] as $menuPage)
                <a class="{{ request()->routeIs('pages.show', 'localized.pages.show') && (($page ?? null) instanceof \App\Models\Page) && $page->is($menuPage) ? 'active' : '' }}" href="{{ page_url($menuPage) }}">{{ $menuPage->titleFor() }}</a>
            @endforeach
            @auth
                <a class="mobile-account-link {{ request()->routeIs('account', 'localized.account') ? 'active' : '' }}" href="{{ public_route('account') }}">{{ __('Account') }}</a>
            @else
                <a class="mobile-account-link {{ request()->routeIs('login', 'localized.login') ? 'active' : '' }}" href="{{ public_route('login') }}">{{ __('Sign in') }}</a>
                @if (registration_available())
                    <a class="mobile-account-link {{ request()->routeIs('register', 'localized.register') ? 'active' : '' }}" href="{{ public_route('register') }}">{{ __('Register') }}</a>
                @endif
            @endauth
        </nav>

        <div class="header-actions">
            @if(count($enabledLanguages ?? []) > 1)
                <div class="language-switcher" aria-label="{{ __('Switch language') }}">
                    @foreach($enabledLanguages as $code => $language)
                        <a class="{{ app()->getLocale() === $code ? 'active' : '' }}" href="{{ route('language.switch', ['locale' => $code, 'return' => request()->getRequestUri()]) }}" lang="{{ $code }}" hreflang="{{ $code }}">
                            {{ strtoupper($code) }}
                        </a>
                    @endforeach
                </div>
            @endif

            @auth
                <a class="button button-gold" href="{{ public_route('account') }}">{{ __('Account') }}</a>
                <form class="header-logout-form" method="POST" action="{{ public_route('logout') }}">
                    @csrf
                    <button class="button button-ghost" type="submit">{{ __('Sign out') }}</button>
                </form>
            @else
                <a class="button button-ghost" href="{{ public_route('login') }}">{{ __('Sign in') }}</a>
                @if (registration_available())
                    <a class="button button-gold" href="{{ public_route('register') }}">{{ __('Register') }}</a>
                @endif
            @endauth
        </div>
    </div>
</header>
