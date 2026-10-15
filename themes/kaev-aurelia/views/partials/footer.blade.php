<footer class="site-footer footer-portal">
    <div class="container footer-statement">
        <a wire:navigate.hover class="footer-emblem" href="{{ public_route('home') }}" aria-label="{{ site_name() }} — {{ __('Go to the home page') }}">
            <span aria-hidden="true">✦</span>
        </a>
        @if (site_description() !== '')
            <h2>{{ site_description() }}</h2>
        @endif
        <p class="footer-quote">«{{ __('Where elven towers touch the clouds, and blades ring in the name of honor.') }}»</p>
    </div>

    <div class="container footer-grid">
        <div class="footer-sections">
            <h3>{{ __('Sections') }}</h3>
            <div class="footer-section-links">
                <a wire:navigate.hover href="{{ public_route('home') }}">{{ __('Home') }}</a>
                <a wire:navigate.hover href="{{ public_route('news.index') }}">{{ __('News') }}</a>
                @if($statisticsNavigationAvailable ?? false)
                    <a wire:navigate.hover href="{{ public_route('statistics.index') }}">{{ __('Statistics') }}</a>
                @endif
                <a wire:navigate.hover href="{{ public_route('about') }}">{{ __('Server description') }}</a>
                @foreach ($footerPages ?? [] as $menuPage)
                    <a wire:navigate.hover href="{{ page_url($menuPage) }}">{{ $menuPage->titleFor() }}</a>
                @endforeach
            </div>
        </div>

        <div class="footer-resources">
            <h3>{{ __('Resources') }}</h3>
            <div class="footer-section-links">
                <a wire:navigate.hover href="{{ public_route('downloads') }}">{{ __('Download client') }}</a>
            </div>
        </div>

        @if (count($footerCustomLinks ?? []) > 0)
            <div class="footer-community">
                <h3>{{ __('Community') }}</h3>
                <div class="footer-section-links footer-custom-links">
                    @foreach ($footerCustomLinks as $customLink)
                        <a href="{{ $customLink->url }}" @if ($customLink->open_new_tab) target="_blank" rel="noopener noreferrer" @endif>{{ $customLink->titleFor() }}</a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="footer-account">
            <h3>{{ __('Personal account') }}</h3>
            <div class="footer-section-links">
                @auth
                    <a wire:navigate.hover href="{{ public_route('account') }}">{{ __('Personal account') }}</a>
                @else
                    <a wire:navigate.hover href="{{ public_route('login') }}">{{ __('Log in') }}</a>
                    @if (registration_available())
                        <a wire:navigate.hover href="{{ public_route('register') }}">{{ __('Register') }}</a>
                    @endif
                @endauth
            </div>
        </div>
    </div>

    <div class="container footer-bottom">
        <span>{{ site_footer_text() }}</span>
        <span>{{ __('Lineage II is a trademark of its respective owners.') }}</span>
    </div>
</footer>
