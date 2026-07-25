<footer class="site-footer">
    <div class="container footer-cta panel">
        <div class="footer-cta-mark" aria-hidden="true"><img src="{{ theme_asset('images/kaev-mark.png') }}" alt=""></div>
        <div>
            <p class="eyebrow">{{ __('PREPARING TO PLAY') }}</p>
            <h2>{{ __('Create account') }}</h2>
            @if (site_description() !== '')
                <p>{{ site_description() }}</p>
            @endif
        </div>
        <div class="footer-cta-actions">
            @auth
                <a wire:navigate.hover class="button button-gold" href="{{ public_route('account') }}">{{ __('Personal account') }} <span aria-hidden="true">→</span></a>
            @elseif (registration_available())
                <a wire:navigate.hover class="button button-gold" href="{{ public_route('register') }}">{{ __('Register') }} <span aria-hidden="true">→</span></a>
            @else
                <a wire:navigate.hover class="button button-gold" href="{{ public_route('login') }}">{{ __('Log in') }} <span aria-hidden="true">→</span></a>
            @endauth
            <a wire:navigate.hover class="button button-ghost" href="{{ public_route('downloads') }}">{{ __('Download client') }}</a>
        </div>
    </div>

    <div class="container footer-grid">
        <div class="footer-intro">
            <a wire:navigate.hover class="brand footer-brand" href="{{ public_route('home') }}">
                @if (site_logo_url())
                    <img class="brand-logo footer-brand-logo brand-logo-custom" src="{{ site_logo_url() }}" alt="{{ site_name() }}">
                @else
                    <img class="brand-logo footer-brand-logo brand-logo-kaev" src="{{ theme_asset('images/kaev-logo.png') }}" alt="Kaev">
                @endif
            </a>
            @if (site_description() !== '')
                <p>{{ site_description() }}</p>
            @endif
        </div>

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

        <div class="footer-community">
            <h3>{{ __('Community') }}</h3>
            <div class="socials"><a href="#">Discord</a><a href="#">Telegram</a><a href="#">VK</a></div>
        </div>

        <div class="footer-decoration" aria-hidden="true"><img src="{{ theme_asset('images/kaev-mark.png') }}" alt=""></div>
    </div>

    <div class="container footer-bottom">
        <span>{{ site_footer_text() }}</span>
        <span>{{ __('Lineage II is a trademark of its respective owners.') }}</span>
    </div>
</footer>
