@extends('theme::layouts.app')

@php
    $heroDetails = $server
        ? array_values(array_filter([
            $server['show_chronicle'] ? $server['chronicle'] : null,
            $server['show_rates'] ? $server['rates'] : null,
            $server['show_mode'] ? $server['mode'] : null,
        ], static fn ($value) => is_string($value) && $value !== ''))
        : [];

    $featureCards = [
        ['shield', __('Fair play'), __('No paid advantages')],
        ['book', __('Classic world'), __('Familiar mechanics without unnecessary changes')],
        ['sword', __('Active community'), __('Open development and transparent rules')],
    ];

    $worldFeatures = [
        ['○', __('Stable operation'), __('Isolated infrastructure and backups')],
        ['◈', __('Regular events'), __('New content without turning the game into a store')],
        ['♜', __('Classic world'), __('Familiar mechanics without unnecessary changes')],
        ['◎', __('Active community'), __('Open development and transparent rules')],
        ['⚔', __('Fair play'), __('No paid advantages')],
        ['⛨', __('Security'), __('Modern authentication and action auditing')],
    ];
@endphp

@section('title', site_name().($server && $server['show_chronicle'] ? ' — '.$server['chronicle'] : ''))

@section('content')
<section class="hero">
    <div class="hero-art" aria-hidden="true"></div>
    <div class="hero-light" aria-hidden="true"></div>
    <div class="container hero-content">
        <div class="hero-copy-block">
            <p class="eyebrow">{{ __('CLASSIC WORLD · YOUR LEGEND') }}</p>
            <h1>{{ site_name() }}</h1>
            <div class="hero-divider" aria-hidden="true"><span></span></div>

            @if ($heroDetails !== [])
                <div class="hero-meta">
                    @foreach ($heroDetails as $detail)<span>{{ $detail }}</span>@endforeach
                </div>
            @endif

            @if (site_description() !== '')
                <p class="hero-copy">{{ site_description() }}</p>
            @endif

            <div class="hero-actions">
                @auth
                    <a wire:navigate.hover class="button button-gold button-large" href="{{ public_route('account') }}">{{ __('Personal account') }} <span aria-hidden="true">→</span></a>
                @elseif (registration_available())
                    <a wire:navigate.hover class="button button-gold button-large" href="{{ public_route('register') }}">{{ __('Register') }} <span aria-hidden="true">→</span></a>
                @else
                    <a wire:navigate.hover class="button button-gold button-large" href="{{ public_route('login') }}">{{ __('Log in') }} <span aria-hidden="true">→</span></a>
                @endauth
                <a wire:navigate.hover class="button button-ghost button-large" href="{{ public_route('downloads') }}">{{ __('Download client') }} <span class="button-play" aria-hidden="true">▷</span></a>
            </div>
        </div>
    </div>
</section>

<section class="container home-shell">
    <div class="hero-feature-grid">
        @foreach($featureCards as [$icon, $title, $text])
            <article class="hero-feature-card panel">
                <span class="ornament-icon ornament-{{ $icon }}" aria-hidden="true"></span>
                <div><h2>{{ $title }}</h2><p>{{ $text }}</p></div>
                <i aria-hidden="true">›</i>
            </article>
        @endforeach
    </div>

    @if ($servers !== [])
        <article
            class="server-panel panel"
            data-server-monitor
            data-refresh-url="{{ public_route('server-monitor.refresh') }}"
            data-auto-refresh="{{ $monitorRefreshDue ? '1' : '0' }}"
        >
            <div class="server-panel-label"><span></span>{{ count($servers) > 1 ? __('Game servers') : __('Server status') }}</div>
            <div class="server-list">
                @foreach ($servers as $currentServer)
                    @php
                        $statusLabel = match ($currentServer['availability_state']) {
                            'maintenance' => __('Maintenance'),
                            'online' => __('Available'),
                            'offline' => __('Unavailable'),
                            default => __('Status pending'),
                        };
                    @endphp
                    <div class="server-row" data-monitor-game-server="{{ $currentServer['id'] }}">
                        <div class="server-summary server-status-cell">
                            <span class="status-dot {{ $currentServer['availability_state'] }}" aria-hidden="true"></span>
                            <div>
                                <small>{{ __('Server status') }}</small>
                                <strong>{{ $currentServer['name'] }}</strong>
                                <span class="status {{ $currentServer['availability_state'] }}" data-monitor-public-state>{{ $statusLabel }}</span>
                                <small class="server-maintenance-message" data-monitor-maintenance-message @if($currentServer['availability_state'] !== 'maintenance' || $currentServer['maintenance_message'] === '') hidden @endif>{{ $currentServer['maintenance_message'] }}</small>
                            </div>
                        </div>

                        @if($publicOnlineVisible)
                            <div class="server-stat" data-monitor-online-cell @if($currentServer['availability_state'] === 'maintenance') hidden @endif>
                                <span class="server-stat-icon" aria-hidden="true">♙</span>
                                <div><small>{{ __('Online') }}</small><strong data-monitor-public-online aria-live="polite">{{ $currentServer['public_players'] !== null ? number_format($currentServer['public_players'], 0, '.', ' ') : '—' }}</strong></div>
                            </div>
                        @endif

                        @if ($currentServer['show_chronicle'])
                            <div class="server-stat"><span class="server-stat-icon" aria-hidden="true">♜</span><div><small>{{ __('Chronicle') }}</small><strong>{{ $currentServer['chronicle'] }}</strong></div></div>
                        @endif
                        @if ($currentServer['show_rates'])
                            <div class="server-stat"><span class="server-stat-icon" aria-hidden="true">×</span><div><small>{{ __('Rates') }}</small><strong>{{ $currentServer['rates'] }}</strong></div></div>
                        @endif
                        @if ($currentServer['show_mode'])
                            <div class="server-stat"><span class="server-stat-icon" aria-hidden="true">⚔</span><div><small>{{ __('Mode') }}</small><strong>{{ $currentServer['mode'] }}</strong></div></div>
                        @endif
                        <a wire:navigate.hover class="server-arrow" href="{{ public_route('about') }}" aria-label="{{ __('About the server') }}">→</a>
                    </div>
                @endforeach
            </div>
        </article>
    @endif

    <section class="home-section news-section">
        <div class="section-heading section-heading-ornamented">
            <span aria-hidden="true"></span>
            <div><p class="eyebrow">{{ __('News') }}</p><h2>{{ __('Latest news') }}</h2></div>
            <span aria-hidden="true"></span>
        </div>

        <div class="news-showcase">
            @forelse($news->take(3) as $item)
                <article class="news-card panel">
                    <a wire:navigate.hover class="news-thumb" href="{{ news_url($item) }}" aria-label="{{ $item->titleFor() }}">
                        @if ($item->coverUrl())
                            <img src="{{ $item->coverUrl() }}" alt="">
                        @else
                            <span class="news-thumb-fallback" aria-hidden="true"><img src="{{ theme_asset('images/kaev-mark.png') }}" alt=""></span>
                        @endif
                    </a>
                    <div class="news-card-copy">
                        <time>{{ $item->published_at?->format('d.m.Y') }}</time>
                        <h3><a wire:navigate.hover href="{{ news_url($item) }}">{{ $item->titleFor() }}</a></h3>
                        <p>{{ $item->excerptFor() }}</p>
                        <a wire:navigate.hover class="news-read" href="{{ news_url($item) }}">{{ __('Read news →') }}</a>
                    </div>
                </article>
            @empty
                <div class="panel empty-state"><img src="{{ theme_asset('images/kaev-mark.png') }}" alt="" aria-hidden="true"><p class="empty">{{ __('There is no news yet.') }}</p></div>
            @endforelse
        </div>
        @if($news->isNotEmpty())
            <div class="section-more"><a wire:navigate.hover class="button button-ghost" href="{{ public_route('news.index') }}">{{ __('All news →') }}</a></div>
        @endif
    </section>

    <section class="home-section features-section">
        <div class="section-heading section-heading-ornamented">
            <span aria-hidden="true"></span>
            <div><p class="eyebrow">{{ __('Classic world') }}</p><h2>{{ __('Server features') }}</h2></div>
            <span aria-hidden="true"></span>
        </div>
        <div class="features-grid">
            @foreach($worldFeatures as [$icon, $title, $text])
                <article class="feature-card panel"><span class="feature-icon" aria-hidden="true">{{ $icon }}</span><div><h3>{{ $title }}</h3><p>{{ $text }}</p></div></article>
            @endforeach
        </div>
    </section>


</section>
@endsection
